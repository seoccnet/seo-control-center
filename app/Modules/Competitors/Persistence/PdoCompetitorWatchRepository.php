<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Competitors\CompetitorWatchRepository;

final readonly class PdoCompetitorWatchRepository implements CompetitorWatchRepository
{
    private const int CHUNK = 500;

    public function __construct(private Connection $db, private Clock $clock) {}

    public function enabled(int $projectId): bool
    {
        return (int) $this->db->fetchValue('SELECT enabled FROM competitor_watch WHERE project_id = ?', [$projectId]) === 1;
    }

    public function setEnabled(int $projectId, int $workspaceId, bool $enabled): void
    {
        $this->db->execute(
            'INSERT INTO competitor_watch (project_id, workspace_id, enabled, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = VALUES(updated_at)',
            [$projectId, $workspaceId, $enabled ? 1 : 0, $this->clock->now()->format('Y-m-d H:i:s')],
        );
    }

    public function due(string $today, int $limit): array
    {
        $rows = $this->db->fetchAll(
            sprintf(
                'SELECT c.id, c.project_id, p.workspace_id, c.domain
                 FROM competitor_watch w
                 JOIN projects p ON p.id = w.project_id AND p.workspace_id = w.workspace_id AND p.deleted_at IS NULL
                 JOIN competitors c ON c.project_id = p.id
                 LEFT JOIN competitor_sitemaps s ON s.competitor_id = c.id
                 WHERE w.enabled = 1 AND (s.checked_on IS NULL OR s.checked_on < ?)
                 ORDER BY s.checked_on IS NOT NULL, s.checked_on, c.id
                 LIMIT %d',
                max(1, $limit),
            ),
            [$today],
        );

        return array_map(static fn(array $r): array => ['competitorId' => (int) $r['id'], 'projectId' => (int) $r['project_id'], 'workspaceId' => (int) $r['workspace_id'], 'domain' => (string) $r['domain']], $rows);
    }

    public function states(int $projectId): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT competitor_id, checked_on, baseline_on, urls, capped, error FROM competitor_sitemaps WHERE project_id = ?', [$projectId]) as $row) {
            $out[(int) $row['competitor_id']] = [
                'checked_on' => (string) $row['checked_on'],
                'baseline_on' => $row['baseline_on'] !== null ? (string) $row['baseline_on'] : null,
                'urls' => $row['urls'] !== null ? (int) $row['urls'] : null,
                'capped' => (bool) $row['capped'],
                'error' => $row['error'] !== null ? (string) $row['error'] : null,
            ];
        }

        return $out;
    }

    public function record(int $competitorId, int $projectId, array $urls, string $today, bool $capped): array
    {
        return $this->db->transaction(function () use ($competitorId, $projectId, $urls, $today, $capped): array {
            $baseline = $this->db->fetchValue('SELECT baseline_on FROM competitor_sitemaps WHERE competitor_id = ? FOR UPDATE', [$competitorId]);
            $isBaseline = $baseline === null || $baseline === false;
            $known = [];
            foreach ($this->db->fetchAll('SELECT url_hash, removed_on FROM competitor_pages WHERE competitor_id = ?', [$competitorId]) as $row) {
                $known[(string) $row['url_hash']] = $row['removed_on'] !== null;
            }
            $listed = [];
            $added = 0;
            $returned = [];
            $insert = [];
            foreach ($urls as $url) {
                $hash = hash('sha256', $url);
                if (isset($listed[$hash])) {
                    continue;
                }
                $listed[$hash] = true;
                if (!array_key_exists($hash, $known)) {
                    $insert[] = [$competitorId, $hash, $projectId, mb_substr($url, 0, 2048), $isBaseline ? null : $today];
                    $added += $isBaseline ? 0 : 1;
                } elseif ($known[$hash]) {
                    $returned[] = $hash;
                }
            }
            foreach (array_chunk($insert, self::CHUNK) as $chunk) {
                $this->db->execute(
                    'INSERT IGNORE INTO competitor_pages (competitor_id, url_hash, project_id, url, first_seen) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)')),
                    array_merge(...$chunk),
                );
            }
            foreach (array_chunk($returned, self::CHUNK) as $chunk) {
                $this->db->execute('UPDATE competitor_pages SET removed_on = NULL WHERE competitor_id = ? AND url_hash IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', [$competitorId, ...$chunk]);
            }
            $removed = 0;
            if (!$capped && !$isBaseline) {
                $gone = array_keys(array_filter($known, static fn(bool $isRemoved, string $hash): bool => !$isRemoved && !isset($listed[$hash]), ARRAY_FILTER_USE_BOTH));
                foreach (array_chunk($gone, self::CHUNK) as $chunk) {
                    $removed += $this->db->execute('UPDATE competitor_pages SET removed_on = ? WHERE competitor_id = ? AND url_hash IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', [$today, $competitorId, ...$chunk]);
                }
            }
            $this->db->execute(
                'INSERT INTO competitor_sitemaps (competitor_id, project_id, checked_on, baseline_on, urls, capped, error) VALUES (?, ?, ?, ?, ?, ?, NULL)
                 ON DUPLICATE KEY UPDATE checked_on = VALUES(checked_on), baseline_on = COALESCE(baseline_on, VALUES(baseline_on)), urls = VALUES(urls), capped = VALUES(capped), error = NULL',
                [$competitorId, $projectId, $today, $today, count($listed), $capped ? 1 : 0],
            );

            return ['added' => $added, 'removed' => $removed];
        });
    }

    public function fail(int $competitorId, int $projectId, string $today, string $error): void
    {
        $this->db->execute(
            'INSERT INTO competitor_sitemaps (competitor_id, project_id, checked_on, error) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE checked_on = VALUES(checked_on), error = VALUES(error)',
            [$competitorId, $projectId, $today, mb_substr($error, 0, 255)],
        );
    }

    public function changes(int $projectId, string $since, int $limit): array
    {
        $rows = $this->db->fetchAll(
            sprintf(
                "(SELECT competitor_id, url, 'new' AS kind, first_seen AS day FROM competitor_pages WHERE project_id = ? AND first_seen >= ?)
                 UNION ALL
                 (SELECT competitor_id, url, 'removed' AS kind, removed_on AS day FROM competitor_pages WHERE project_id = ? AND removed_on >= ?)
                 ORDER BY day DESC, kind, url
                 LIMIT %d",
                max(1, $limit),
            ),
            [$projectId, $since, $projectId, $since],
        );

        return array_map(static fn(array $r): array => ['competitorId' => (int) $r['competitor_id'], 'url' => (string) $r['url'], 'kind' => (string) $r['kind'], 'date' => (string) $r['day']], $rows);
    }
}
