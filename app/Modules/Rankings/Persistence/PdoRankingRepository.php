<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\PositionFilter;
use SeoControlCenter\Domain\Rankings\RankCheck;
use SeoControlCenter\Domain\Rankings\RankedKeyword;
use SeoControlCenter\Domain\Rankings\RankingDay;
use SeoControlCenter\Domain\Rankings\RankingMove;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankingSyncState;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;

final readonly class PdoRankingRepository implements RankingRepository
{
    /** Keyword table: how far back "previous" and "best" positions are looked up. */
    private const int HISTORY_DAYS = 365;
    /** Latest (rn = 1) and previous (rn = 2) check per keyword with the best position; parameters: project, device, since. */
    private const string RECENT = 'WITH recent AS (
            SELECT project_keyword_id, position, url, serp_features, checked_on,
                   ROW_NUMBER() OVER (PARTITION BY project_keyword_id ORDER BY checked_on DESC) AS rn,
                   MIN(position) OVER (PARTITION BY project_keyword_id) AS best
            FROM ranking_snapshots
            WHERE project_id = ? AND device = ? AND checked_on >= ?
        )';

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function settings(int $projectId): RankingSettings
    {
        $row = $this->db->fetchOne('SELECT enabled, device, frequency FROM project_ranking_settings WHERE project_id = ?', [$projectId]);

        return $row === null
            ? RankingSettings::default()
            : new RankingSettings((bool) $row['enabled'], SerpDevice::from((string) $row['device']), (string) $row['frequency']);
    }

    public function positionBuckets(int $projectId, int $workspaceId, SerpDevice $device, string $date, ?string $cohortDate = null): array
    {
        $sql = 'SELECT rs.position FROM ranking_snapshots rs
            JOIN project_keywords pk ON pk.id = rs.project_keyword_id AND pk.project_id = rs.project_id
            JOIN projects p ON p.id = pk.project_id AND p.workspace_id = ? AND p.deleted_at IS NULL
            WHERE rs.project_id = ? AND rs.device = ? AND rs.checked_on = ?';
        $params = [$workspaceId, $projectId, $device->value, $date];
        if ($cohortDate !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM ranking_snapshots other WHERE other.project_id = rs.project_id AND other.project_keyword_id = rs.project_keyword_id AND other.device = rs.device AND other.checked_on = ?)';
            $params[] = $cohortDate;
        }
        $rows = $this->db->fetchAll($sql, $params);
        return \SeoControlCenter\Domain\Rankings\PositionBuckets::count(array_map(static fn(array $r): ?int => $r['position'] === null ? null : (int) $r['position'], $rows));
    }

    public function saveSettings(int $projectId, RankingSettings $settings, int $userId): void
    {
        $this->db->execute(
            'INSERT INTO project_ranking_settings (project_id, enabled, device, frequency, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), device = VALUES(device), frequency = VALUES(frequency),
                 updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$projectId, $settings->enabled ? 1 : 0, $settings->device->value, $settings->frequency, $userId, $this->now()],
        );
    }

    public function dueProjects(string $today): array
    {
        $rows = $this->db->fetchAll(
            "SELECT p.id AS project_id, p.workspace_id
             FROM project_ranking_settings s
             JOIN projects p ON p.id = s.project_id AND p.deleted_at IS NULL
             LEFT JOIN project_ranking_sync y ON y.project_id = s.project_id
             WHERE s.enabled = 1
               AND (y.last_checked_on IS NULL
                    OR y.last_checked_on <= DATE_SUB(?, INTERVAL CASE s.frequency WHEN 'weekly' THEN 7 ELSE 1 END DAY))
             ORDER BY p.id",
            [$today],
        );

        return array_map(static fn(array $r): array => ['project_id' => (int) $r['project_id'], 'workspace_id' => (int) $r['workspace_id']], $rows);
    }

    public function trackedProjects(): array
    {
        return array_map(static fn(array $r): array => ['project_id' => (int) $r['project_id'], 'workspace_id' => (int) $r['workspace_id']], $this->db->fetchAll(
            'SELECT p.id AS project_id, p.workspace_id FROM project_ranking_settings s JOIN projects p ON p.id = s.project_id AND p.deleted_at IS NULL WHERE s.enabled = 1 ORDER BY p.id',
        ));
    }

    public function projectDomain(int $projectId, int $workspaceId): ?string
    {
        $domain = $this->db->fetchValue(
            'SELECT primary_domain FROM projects WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL',
            [$projectId, $workspaceId],
        );

        return is_string($domain) ? $domain : null;
    }

    public function unchecked(int $projectId, SerpDevice $device, string $date, int $limit): array
    {
        $rows = $this->db->fetchAll(
            sprintf(
                'SELECT pk.id, pk.keyword, pk.location_code, pk.language_code
                 FROM project_keywords pk
                 LEFT JOIN ranking_snapshots rs ON rs.project_keyword_id = pk.id AND rs.device = ? AND rs.checked_on = ?
                 WHERE pk.project_id = ? AND rs.id IS NULL
                 ORDER BY pk.id
                 LIMIT %d',
                max(1, $limit),
            ),
            [$device->value, $date, $projectId],
        );

        $result = [];
        foreach ($rows as $row) {
            try {
                $locale = new KeywordLocale((int) $row['location_code'], (string) $row['language_code']);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $result[] = ['id' => (int) $row['id'], 'keyword' => (string) $row['keyword'], 'locale' => $locale];
        }

        return $result;
    }

    public function countUnchecked(int $projectId, SerpDevice $device, string $date): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*)
             FROM project_keywords pk
             LEFT JOIN ranking_snapshots rs ON rs.project_keyword_id = pk.id AND rs.device = ? AND rs.checked_on = ?
             WHERE pk.project_id = ? AND rs.id IS NULL',
            [$device->value, $date, $projectId],
        );
    }

    public function storeSnapshot(RankSnapshot $snapshot): void
    {
        $this->db->execute(
            'INSERT INTO ranking_snapshots (project_keyword_id, project_id, device, checked_on, position, absolute_position, url, serp_features, top_results, checked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE position = VALUES(position), absolute_position = VALUES(absolute_position), url = VALUES(url),
                 serp_features = VALUES(serp_features), top_results = VALUES(top_results), checked_at = VALUES(checked_at)',
            [
                $snapshot->projectKeywordId,
                $snapshot->projectId,
                $snapshot->device->value,
                $snapshot->checkedOn,
                $snapshot->position !== null ? min(255, $snapshot->position) : null,
                $snapshot->absolutePosition !== null ? min(65535, $snapshot->absolutePosition) : null,
                $snapshot->url !== null ? mb_substr($snapshot->url, 0, 2048) : null,
                $snapshot->features !== [] ? substr(implode(',', $snapshot->features), 0, 500) : null,
                $snapshot->top !== [] ? json_encode($snapshot->top, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                $this->now(),
            ],
        );
    }

    public function aggregate(int $projectId, int $workspaceId, SerpDevice $device, string $date): void
    {
        $rows = $this->db->fetchAll(
            'SELECT rs.position, km.search_volume
             FROM ranking_snapshots rs
             JOIN project_keywords pk ON pk.id = rs.project_keyword_id
             LEFT JOIN keyword_metrics km
                 ON km.workspace_id = ? AND km.keyword_hash = pk.keyword_hash
                AND km.location_code = pk.location_code AND km.language_code = pk.language_code
             WHERE rs.project_id = ? AND rs.device = ? AND rs.checked_on = ?',
            [$workspaceId, $projectId, $device->value, $date],
        );
        if ($rows === []) {
            return;
        }

        $day = RankingDay::calculate($date, array_map(static fn(array $row): array => [
            'position' => $row['position'] !== null ? (int) $row['position'] : null,
            'volume' => $row['search_volume'] !== null ? (int) $row['search_volume'] : null,
        ], $rows));
        $this->db->execute(
            'INSERT INTO ranking_daily (project_id, device, date, keywords, ranked, top3, top10, average_position, visibility)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE keywords = VALUES(keywords), ranked = VALUES(ranked), top3 = VALUES(top3), top10 = VALUES(top10),
                 average_position = VALUES(average_position), visibility = VALUES(visibility)',
            [
                $projectId,
                $device->value,
                $date,
                $day->keywords,
                $day->ranked,
                $day->top3,
                $day->top10,
                $day->averagePosition,
                $day->visibility,
            ],
        );
    }

    public function days(int $projectId, SerpDevice $device, string $from, string $to): array
    {
        return array_map(self::day(...), $this->db->fetchAll(
            'SELECT date, keywords, ranked, top3, top10, average_position, visibility
             FROM ranking_daily WHERE project_id = ? AND device = ? AND date BETWEEN ? AND ? ORDER BY date',
            [$projectId, $device->value, $from, $to],
        ));
    }

    public function latestDay(int $projectId, SerpDevice $device): ?RankingDay
    {
        return $this->dayOnOrBefore($projectId, $device, '9999-12-31');
    }

    public function dayOnOrBefore(int $projectId, SerpDevice $device, string $date): ?RankingDay
    {
        $row = $this->db->fetchOne(
            'SELECT date, keywords, ranked, top3, top10, average_position, visibility
             FROM ranking_daily WHERE project_id = ? AND device = ? AND date <= ? ORDER BY date DESC LIMIT 1',
            [$projectId, $device->value, $date],
        );

        return $row === null ? null : self::day($row);
    }

    public function keywords(int $projectId, int $workspaceId, SerpDevice $device, int $limit, int $offset, ?PositionFilter $filter = null): array
    {
        $rows = $this->db->fetchAll(
            sprintf(
                self::RECENT . '
                 SELECT pk.public_id, pk.keyword, pk.location_code, pk.language_code,
                        cur.checked_on, cur.position, cur.url, cur.serp_features, cur.best,
                        prev.checked_on AS prev_checked_on, prev.position AS prev_position,
                        km.search_volume
                 FROM project_keywords pk
                 LEFT JOIN recent cur ON cur.project_keyword_id = pk.id AND cur.rn = 1
                 LEFT JOIN recent prev ON prev.project_keyword_id = pk.id AND prev.rn = 2
                 LEFT JOIN keyword_metrics km
                     ON km.workspace_id = ? AND km.keyword_hash = pk.keyword_hash
                    AND km.location_code = pk.location_code AND km.language_code = pk.language_code
                 WHERE pk.project_id = ?' . self::filterSql($filter) . '
                 ORDER BY cur.checked_on IS NULL, cur.position IS NULL, cur.position, km.search_volume DESC, pk.keyword, pk.id
                 LIMIT %d OFFSET %d',
                max(1, $limit),
                max(0, $offset),
            ),
            [$projectId, $device->value, $this->historySince(), $workspaceId, $projectId],
        );

        $result = [];
        foreach ($rows as $row) {
            try {
                $locale = new KeywordLocale((int) $row['location_code'], (string) $row['language_code']);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $result[] = new RankedKeyword(
                (string) $row['public_id'],
                (string) $row['keyword'],
                $locale,
                $row['checked_on'] !== null ? (string) $row['checked_on'] : null,
                $row['position'] !== null ? (int) $row['position'] : null,
                $row['prev_position'] !== null ? (int) $row['prev_position'] : null,
                $row['checked_on'] !== null ? $row['prev_checked_on'] !== null : null,
                $row['best'] !== null ? (int) $row['best'] : null,
                $row['url'] !== null ? (string) $row['url'] : null,
                $row['serp_features'] !== null ? explode(',', (string) $row['serp_features']) : [],
                $row['search_volume'] !== null ? (int) $row['search_volume'] : null,
            );
        }

        return $result;
    }

    public function countKeywords(int $projectId, SerpDevice $device, ?PositionFilter $filter = null): int
    {
        if ($filter === null) {
            return (int) $this->db->fetchValue('SELECT COUNT(*) FROM project_keywords WHERE project_id = ?', [$projectId]);
        }

        return (int) $this->db->fetchValue(
            self::RECENT . '
             SELECT COUNT(*)
             FROM project_keywords pk
             LEFT JOIN recent cur ON cur.project_keyword_id = pk.id AND cur.rn = 1
             WHERE pk.project_id = ?' . self::filterSql($filter),
            [$projectId, $device->value, $this->historySince(), $projectId],
        );
    }

    public function history(int $projectId, string $keywordPublicId, SerpDevice $device, string $since): array
    {
        $rows = $this->db->fetchAll(
            'SELECT rs.checked_on, rs.position, rs.url, rs.serp_features, rs.top_results
             FROM ranking_snapshots rs
             JOIN project_keywords pk ON pk.id = rs.project_keyword_id AND pk.project_id = ? AND pk.public_id = ?
             WHERE rs.device = ? AND rs.checked_on >= ?
             ORDER BY rs.checked_on',
            [$projectId, $keywordPublicId, $device->value, $since],
        );

        return array_map(static function (array $r): RankCheck {
            $top = is_string($r['top_results']) ? json_decode($r['top_results'], true) : null;

            return new RankCheck(
                (string) $r['checked_on'],
                $r['position'] !== null ? (int) $r['position'] : null,
                $r['url'] !== null ? (string) $r['url'] : null,
                $r['serp_features'] !== null ? explode(',', (string) $r['serp_features']) : [],
                self::topResults($top),
            );
        }, $rows);
    }

    public function comparableChecks(int $projectId, SerpDevice $device, string $date, int $maxGapDays): array
    {
        $since = (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify(sprintf('-%d days', $maxGapDays))->format('Y-m-d');
        $rows = $this->db->fetchAll(
            'SELECT pk.id, pk.public_id, pk.keyword,
                    cur.checked_on, cur.position, cur.url, cur.serp_features, cur.top_results,
                    prev.checked_on AS p_on, prev.position AS p_position, prev.url AS p_url, prev.serp_features AS p_features, prev.top_results AS p_top
             FROM ranking_snapshots cur
             JOIN project_keywords pk ON pk.id = cur.project_keyword_id AND pk.project_id = cur.project_id
             LEFT JOIN ranking_snapshots prev ON prev.id = (
                 SELECT p2.id FROM ranking_snapshots p2
                 WHERE p2.project_keyword_id = cur.project_keyword_id AND p2.device = cur.device AND p2.checked_on < cur.checked_on AND p2.checked_on >= ?
                 ORDER BY p2.checked_on DESC LIMIT 1)
             WHERE cur.project_id = ? AND cur.device = ? AND cur.checked_on = ?
             ORDER BY pk.id',
            [$since, $projectId, $device->value, $date],
        );
        $check = static fn(mixed $on, mixed $position, mixed $url, mixed $features, mixed $top): RankCheck => new RankCheck(
            (string) $on,
            $position !== null ? (int) $position : null,
            $url !== null ? (string) $url : null,
            $features !== null && $features !== '' ? explode(',', (string) $features) : [],
            self::topResults(is_string($top) ? json_decode($top, true) : null),
        );

        return array_map(static fn(array $r): array => [
            'keyword_id' => (int) $r['id'],
            'keyword_public_id' => (string) $r['public_id'],
            'keyword' => (string) $r['keyword'],
            'current' => $check($r['checked_on'], $r['position'], $r['url'], $r['serp_features'], $r['top_results']),
            'previous' => $r['p_on'] !== null ? $check($r['p_on'], $r['p_position'], $r['p_url'], $r['p_features'], $r['p_top']) : null,
        ], $rows);
    }

    public function checkDays(int $projectId, SerpDevice $device, string $since): array
    {
        return array_map(static fn(array $r): string => (string) $r['checked_on'], $this->db->fetchAll('SELECT DISTINCT checked_on FROM ranking_snapshots WHERE project_id = ? AND device = ? AND checked_on >= ? ORDER BY checked_on', [$projectId, $device->value, $since]));
    }

    public function moves(int $projectId, SerpDevice $device, string $from, string $to): array
    {
        $rows = $this->db->fetchAll(
            'SELECT pk.keyword, a.position AS from_position, b.position AS to_position
             FROM ranking_snapshots b
             JOIN ranking_snapshots a ON a.project_keyword_id = b.project_keyword_id AND a.device = b.device AND a.checked_on = ?
             JOIN project_keywords pk ON pk.id = b.project_keyword_id
             WHERE b.project_id = ? AND b.device = ? AND b.checked_on = ? AND NOT (a.position <=> b.position)',
            [$from, $projectId, $device->value, $to],
        );

        return array_map(static fn(array $r): RankingMove => new RankingMove(
            (string) $r['keyword'],
            $r['from_position'] !== null ? (int) $r['from_position'] : null,
            $r['to_position'] !== null ? (int) $r['to_position'] : null,
        ), $rows);
    }

    public function syncState(int $projectId): ?RankingSyncState
    {
        $row = $this->db->fetchOne(
            'SELECT status, last_error, last_checked_on, last_success_at FROM project_ranking_sync WHERE project_id = ?',
            [$projectId],
        );

        return $row === null ? null : new RankingSyncState(
            (string) $row['status'],
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            $row['last_checked_on'] !== null ? (string) $row['last_checked_on'] : null,
            $row['last_success_at'] !== null ? (string) $row['last_success_at'] : null,
        );
    }

    public function setSyncState(int $projectId, string $status, ?string $error = null, ?string $checkedOn = null): void
    {
        $now = $this->now();
        $this->db->execute(
            'INSERT INTO project_ranking_sync (project_id, status, last_error, last_checked_on, last_success_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 status = VALUES(status),
                 last_error = VALUES(last_error),
                 last_checked_on = COALESCE(VALUES(last_checked_on), last_checked_on),
                 last_success_at = COALESCE(VALUES(last_success_at), last_success_at),
                 updated_at = VALUES(updated_at)',
            [
                $projectId,
                $status,
                $status === RankingSyncState::ERROR && $error !== null ? mb_substr($error, 0, 500) : null,
                $checkedOn,
                $status === RankingSyncState::OK ? $now : null,
                $now,
            ],
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

    /**
     * @return list<array{position: int, domain: string, url: string}>
     */
    private static function topResults(mixed $data): array
    {
        $top = [];
        foreach (is_array($data) ? $data : [] as $row) {
            if (is_array($row) && is_int($row['position'] ?? null) && is_string($row['domain'] ?? null) && is_string($row['url'] ?? null)) {
                $top[] = ['position' => $row['position'], 'domain' => $row['domain'], 'url' => $row['url']];
            }
        }

        return $top;
    }

    private static function filterSql(?PositionFilter $filter): string
    {
        return match ($filter) {
            null => '',
            PositionFilter::Top3 => ' AND cur.position <= 3',
            PositionFilter::Top10 => ' AND cur.position <= 10',
            PositionFilter::Page2 => ' AND cur.position BETWEEN 11 AND 20',
            PositionFilter::Deep => ' AND cur.position >= 21',
            PositionFilter::NotRanking => ' AND cur.checked_on IS NOT NULL AND cur.position IS NULL',
        };
    }

    private function historySince(): string
    {
        return $this->clock->now()->modify(sprintf('-%d days', self::HISTORY_DAYS))->format('Y-m-d');
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
