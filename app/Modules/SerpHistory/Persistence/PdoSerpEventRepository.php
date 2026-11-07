<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SerpHistory\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\SerpHistory\SerpChangeDetector;
use SeoControlCenter\Domain\SerpHistory\SerpEvent;
use SeoControlCenter\Domain\SerpHistory\SerpEventRepository;

final readonly class PdoSerpEventRepository implements SerpEventRepository
{
    public function __construct(private Connection $db, private Clock $clock) {}

    public function record(int $projectId, int $workspaceId, string $device, string $checkedOn, array $events): int
    {
        $added = 0;
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        foreach ($events as $e) {
            $added += $this->db->execute(
                'INSERT IGNORE INTO serp_events (workspace_id, project_id, project_keyword_id, device, checked_on, previous_on, kind, subject, subject_hash, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$workspaceId, $projectId, $e['keyword_id'], $device, $checkedOn, $e['previous_on'], $e['kind'], mb_substr($e['subject'], 0, 2048), hash('sha256', $e['subject']), json_encode($e['detail'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now],
            );
        }

        return $added;
    }

    public function list(int $projectId, string $device, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::where($projectId, $device, $filters);
        $rows = $this->db->fetchAll(
            'SELECT pk.keyword, pk.public_id, e.device, e.checked_on, e.previous_on, e.kind, e.subject, e.detail FROM serp_events e JOIN project_keywords pk ON pk.id = e.project_keyword_id WHERE ' . $where . sprintf(' ORDER BY e.checked_on DESC, e.id DESC LIMIT %d OFFSET %d', max(1, $limit), max(0, $offset)),
            $params,
        );

        return array_map(static function (array $r): SerpEvent {
            $detail = is_string($r['detail']) ? json_decode($r['detail'], true) : null;

            return new SerpEvent((string) $r['keyword'], (string) $r['public_id'], (string) $r['device'], (string) $r['checked_on'], (string) $r['previous_on'], (string) $r['kind'], (string) $r['subject'], is_array($detail) ? $detail : []);
        }, $rows);
    }

    public function count(int $projectId, string $device, array $filters): int
    {
        [$where, $params] = self::where($projectId, $device, $filters);

        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM serp_events e JOIN project_keywords pk ON pk.id = e.project_keyword_id WHERE ' . $where, $params);
    }

    public function counts(int $projectId, string $device, string $since): array
    {
        $counts = [];
        foreach ($this->db->fetchAll('SELECT kind, COUNT(*) AS n FROM serp_events WHERE project_id = ? AND device = ? AND checked_on >= ? GROUP BY kind', [$projectId, $device, $since]) as $r) {
            $counts[(string) $r['kind']] = (int) $r['n'];
        }

        return $counts;
    }

    public function competitors(int $projectId, string $device, string $since): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT kind, subject, detail FROM serp_events WHERE project_id = ? AND device = ? AND checked_on >= ? AND kind IN (?, ?, ?) LIMIT 20000',
            [$projectId, $device, $since, SerpChangeDetector::COMPETITOR_ENTERED, SerpChangeDetector::COMPETITOR_LEFT, SerpChangeDetector::NEW_URL],
        ) as $r) {
            $kind = (string) $r['kind'];
            if ($kind === SerpChangeDetector::NEW_URL) {
                $detail = is_string($r['detail']) ? json_decode($r['detail'], true) : null;
                if (!is_array($detail) || ($detail['own'] ?? false) === true || !is_string($detail['domain'] ?? null)) {
                    continue;
                }
                $domain = $detail['domain'];
            } else {
                $domain = (string) $r['subject'];
            }
            $out[$domain] ??= ['new_urls' => 0, 'entered' => 0, 'left' => 0];
            ++$out[$domain][match ($kind) {
                SerpChangeDetector::NEW_URL => 'new_urls', SerpChangeDetector::COMPETITOR_ENTERED => 'entered', default => 'left'
            }];
        }
        ksort($out);

        return $out;
    }

    public function hasAny(int $projectId): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM serp_events WHERE project_id = ? LIMIT 1', [$projectId]) !== null;
    }

    public function prune(string $before): int
    {
        return $this->db->execute('DELETE FROM serp_events WHERE checked_on < ? LIMIT 50000', [$before]);
    }

    /**
     * @param array{kinds?: list<string>, q?: string, subject?: string, from?: string, to?: string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function where(int $projectId, string $device, array $filters): array
    {
        $where = 'e.project_id = ? AND e.device = ?';
        $params = [$projectId, $device];
        if (($filters['kinds'] ?? []) !== []) {
            $where .= ' AND e.kind IN (' . implode(',', array_fill(0, count($filters['kinds']), '?')) . ')';
            $params = [...$params, ...$filters['kinds']];
        }
        if (($filters['q'] ?? '') !== '') {
            $where .= ' AND pk.keyword LIKE ?';
            $params[] = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
        }
        if (($filters['subject'] ?? '') !== '') {
            $where .= ' AND (e.subject LIKE ? OR e.detail LIKE ?)';
            $like = '%' . addcslashes((string) $filters['subject'], '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if (($filters['from'] ?? '') !== '') {
            $where .= ' AND e.checked_on >= ?';
            $params[] = $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where .= ' AND e.checked_on <= ?';
            $params[] = $filters['to'];
        }

        return [$where, $params];
    }
}
