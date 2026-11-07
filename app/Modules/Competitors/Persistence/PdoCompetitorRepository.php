<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\CompetitorCandidate;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Competitors\KeywordComparison;
use SeoControlCenter\Domain\Rankings\RankingDay;
use SeoControlCenter\Domain\Rankings\SerpDevice;

final readonly class PdoCompetitorRepository implements CompetitorRepository
{
    private const int INSERT_CHUNK = 500;
    private const string COLUMNS = 'id, public_id, project_id, domain, source, created_at';

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function forProject(int $projectId): array
    {
        return array_map(self::competitor(...), $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM competitors WHERE project_id = ? ORDER BY domain',
            [$projectId],
        ));
    }

    public function find(int $projectId, string $publicId): ?Competitor
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM competitors WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]);

        return $row === null ? null : self::competitor($row);
    }

    public function add(int $projectId, string $domain, string $source, int $userId): ?Competitor
    {
        $publicId = Uuid::v7($this->clock);
        $inserted = $this->db->execute(
            'INSERT IGNORE INTO competitors (public_id, project_id, domain, source, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$publicId, $projectId, $domain, $source, $userId, $this->clock->now()->format('Y-m-d H:i:s')],
        );

        return $inserted > 0 ? $this->find($projectId, $publicId) : null;
    }

    public function remove(int $projectId, string $publicId): bool
    {
        return $this->db->execute('DELETE FROM competitors WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]) > 0;
    }

    public function recordPositions(int $projectKeywordId, SerpDevice $device, string $date, array $positions): void
    {
        foreach (array_chunk($positions, self::INSERT_CHUNK) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $p) {
                $rows[] = '(?, ?, ?, ?, ?, ?)';
                array_push($params, $p['competitor_id'], $projectKeywordId, $device->value, $date, $p['position'] !== null ? min(255, $p['position']) : null, $p['url'] !== null ? mb_substr($p['url'], 0, 2048) : null);
            }
            $this->db->execute(
                'INSERT INTO competitor_rankings (competitor_id, project_keyword_id, device, checked_on, position, url)
                 VALUES ' . implode(', ', $rows) . '
                 ON DUPLICATE KEY UPDATE position = VALUES(position), url = VALUES(url)',
                $params,
            );
        }
    }

    public function aggregate(int $projectId, int $workspaceId, SerpDevice $device, string $date): void
    {
        $rows = $this->db->fetchAll(
            'SELECT cr.competitor_id, cr.position, km.search_volume
             FROM competitor_rankings cr
             JOIN competitors c ON c.id = cr.competitor_id AND c.project_id = ?
             JOIN project_keywords pk ON pk.id = cr.project_keyword_id
             LEFT JOIN keyword_metrics km
                 ON km.workspace_id = ? AND km.keyword_hash = pk.keyword_hash
                AND km.location_code = pk.location_code AND km.language_code = pk.language_code
             WHERE cr.device = ? AND cr.checked_on = ?',
            [$projectId, $workspaceId, $device->value, $date],
        );

        $byCompetitor = [];
        foreach ($rows as $row) {
            $byCompetitor[(int) $row['competitor_id']][] = [
                'position' => $row['position'] !== null ? (int) $row['position'] : null,
                'volume' => $row['search_volume'] !== null ? (int) $row['search_volume'] : null,
            ];
        }
        foreach ($byCompetitor as $competitorId => $keywords) {
            $day = RankingDay::calculate($date, $keywords);
            $this->db->execute(
                'INSERT INTO competitor_daily (competitor_id, device, date, keywords, ranked, top3, top10, average_position, visibility)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE keywords = VALUES(keywords), ranked = VALUES(ranked), top3 = VALUES(top3), top10 = VALUES(top10),
                     average_position = VALUES(average_position), visibility = VALUES(visibility)',
                [$competitorId, $device->value, $date, $day->keywords, $day->ranked, $day->top3, $day->top10, $day->averagePosition, $day->visibility],
            );
        }
    }

    public function backfill(Competitor $competitor, SerpDevice $device, array $dates): array
    {
        if ($dates === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT project_keyword_id, checked_on, top_results FROM ranking_snapshots
             WHERE project_id = ? AND device = ? AND checked_on IN (' . implode(', ', array_fill(0, count($dates), '?')) . ')',
            [$competitor->projectId, $device->value, ...$dates],
        );

        $days = [];
        $insert = [];
        foreach ($rows as $row) {
            $position = null;
            $url = null;
            $top = is_string($row['top_results']) ? json_decode($row['top_results'], true) : null;
            foreach (is_array($top) ? $top : [] as $result) {
                if (is_array($result) && is_string($result['domain'] ?? null) && is_int($result['position'] ?? null)
                    && Competitor::owns($competitor->domain, $result['domain'])) {
                    $position = $result['position'];
                    $url = is_string($result['url'] ?? null) ? $result['url'] : null;
                    break;
                }
            }
            $days[(string) $row['checked_on']] = true;
            array_push($insert, $competitor->id, (int) $row['project_keyword_id'], $device->value, (string) $row['checked_on'], $position, $url !== null ? mb_substr($url, 0, 2048) : null);
        }
        // Real check results (if any) win over the top-10 estimate.
        foreach (array_chunk($insert, self::INSERT_CHUNK * 6) as $chunk) {
            $this->db->execute(
                'INSERT IGNORE INTO competitor_rankings (competitor_id, project_keyword_id, device, checked_on, position, url) VALUES '
                . implode(', ', array_fill(0, intdiv(count($chunk), 6), '(?, ?, ?, ?, ?, ?)')),
                $chunk,
            );
        }

        return array_map('strval', array_keys($days));
    }

    public function days(int $projectId, SerpDevice $device, string $date): array
    {
        $days = [];
        foreach ($this->dailyRows($projectId, $device, $date) as $row) {
            $days[(int) $row['competitor_id']] = self::day($row);
        }

        return $days;
    }

    public function compare(int $projectId, int $workspaceId, int $competitorId, SerpDevice $device, string $date): array
    {
        $rows = $this->db->fetchAll(
            'SELECT pk.public_id, pk.keyword, km.search_volume, rs.position AS own_position, cr.position AS their_position, cr.url AS their_url
             FROM project_keywords pk
             JOIN ranking_snapshots rs ON rs.project_keyword_id = pk.id AND rs.device = ? AND rs.checked_on = ?
             JOIN competitors c ON c.id = ? AND c.project_id = pk.project_id
             LEFT JOIN competitor_rankings cr
                 ON cr.competitor_id = c.id AND cr.project_keyword_id = pk.id AND cr.device = rs.device AND cr.checked_on = rs.checked_on
             LEFT JOIN keyword_metrics km
                 ON km.workspace_id = ? AND km.keyword_hash = pk.keyword_hash
                AND km.location_code = pk.location_code AND km.language_code = pk.language_code
             WHERE pk.project_id = ?
             ORDER BY km.search_volume DESC, pk.keyword',
            [$device->value, $date, $competitorId, $workspaceId, $projectId],
        );

        return array_map(static fn(array $r): KeywordComparison => new KeywordComparison(
            (string) $r['public_id'],
            (string) $r['keyword'],
            $r['search_volume'] !== null ? (int) $r['search_volume'] : null,
            $r['own_position'] !== null ? (int) $r['own_position'] : null,
            $r['their_position'] !== null ? (int) $r['their_position'] : null,
            $r['their_url'] !== null ? (string) $r['their_url'] : null,
        ), $rows);
    }

    public function candidates(int $projectId, SerpDevice $device, string $date, string $ownDomain, int $limit): array
    {
        $existing = array_map(static fn(Competitor $c): string => $c->domain, $this->forProject($projectId));
        $ownDomain = Competitor::normalizeDomain($ownDomain);
        $rows = $this->db->fetchAll(
            'SELECT top_results FROM ranking_snapshots WHERE project_id = ? AND device = ? AND checked_on = ? AND top_results IS NOT NULL',
            [$projectId, $device->value, $date],
        );

        $stats = [];
        foreach ($rows as $row) {
            $seen = [];
            $top = json_decode((string) $row['top_results'], true);
            foreach (is_array($top) ? $top : [] as $result) {
                if (!is_array($result) || !is_string($result['domain'] ?? null) || !is_int($result['position'] ?? null)) {
                    continue;
                }
                $domain = Competitor::normalizeDomain($result['domain']);
                if (isset($seen[$domain]) || Competitor::owns($ownDomain, $domain)) {
                    continue;
                }
                foreach ($existing as $known) {
                    if (Competitor::owns($known, $domain)) {
                        continue 2;
                    }
                }
                $seen[$domain] = true; // best position per keyword only
                $stats[$domain]['keywords'] = ($stats[$domain]['keywords'] ?? 0) + 1;
                $stats[$domain]['sum'] = ($stats[$domain]['sum'] ?? 0) + $result['position'];
                $stats[$domain]['top3'] = ($stats[$domain]['top3'] ?? 0) + ($result['position'] <= 3 ? 1 : 0);
            }
        }

        $candidates = [];
        foreach ($stats as $domain => $s) {
            $candidates[] = new CompetitorCandidate((string) $domain, $s['keywords'], round($s['sum'] / $s['keywords'], 1), $s['top3']);
        }
        usort($candidates, static fn(CompetitorCandidate $a, CompetitorCandidate $b): int => [$b->keywords, $a->averagePosition, $a->domain] <=> [$a->keywords, $b->averagePosition, $b->domain]);

        return array_slice($candidates, 0, max(1, $limit));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dailyRows(int $projectId, SerpDevice $device, string $date): array
    {
        return $this->db->fetchAll(
            'SELECT cd.competitor_id, cd.date, cd.keywords, cd.ranked, cd.top3, cd.top10, cd.average_position, cd.visibility
             FROM competitor_daily cd
             JOIN competitors c ON c.id = cd.competitor_id AND c.project_id = ?
             WHERE cd.device = ? AND cd.date = ?',
            [$projectId, $device->value, $date],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function competitor(array $row): Competitor
    {
        return new Competitor(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['project_id'],
            (string) $row['domain'],
            (string) $row['source'],
            (string) $row['created_at'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function day(array $row): RankingDay
    {
        return new RankingDay(
            (string) $row['date'],
            (int) $row['keywords'],
            (int) $row['ranked'],
            (int) $row['top3'],
            (int) $row['top10'],
            $row['average_position'] !== null ? (float) $row['average_position'] : null,
            (float) $row['visibility'],
        );
    }
}
