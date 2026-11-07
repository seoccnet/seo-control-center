<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\IndexNow\IndexNowRepository;
use SeoControlCenter\Modules\IndexNow\IndexNowSettings;

final readonly class PdoIndexNowRepository implements IndexNowRepository
{
    private const int INSERT_CHUNK = 500;

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function settings(int $projectId): ?IndexNowSettings
    {
        $row = $this->db->fetchOne('SELECT * FROM indexnow_settings WHERE project_id = ?', [$projectId]);

        return $row === null ? null : new IndexNowSettings(
            (int) $row['project_id'],
            (int) $row['workspace_id'],
            (string) $row['api_key'],
            $row['host'] !== null ? (string) $row['host'] : null,
            $row['key_verified_at'] !== null ? (string) $row['key_verified_at'] : null,
            $row['sitemap_url'] !== null ? (string) $row['sitemap_url'] : null,
            (bool) $row['auto_submit'],
            $row['sitemap_checked_at'] !== null ? (string) $row['sitemap_checked_at'] : null,
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            (string) ($row['key_path'] ?? '/'),
        );
    }

    public function create(int $projectId, int $workspaceId, string $key): void
    {
        $now = $this->now();
        $this->db->execute(
            'INSERT INTO indexnow_settings (project_id, workspace_id, api_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE api_key = VALUES(api_key), host = NULL, key_path = DEFAULT(key_path), key_verified_at = NULL, updated_at = VALUES(updated_at)',
            [$projectId, $workspaceId, $key, $now, $now],
        );
    }

    public function markVerified(int $projectId, string $host, string $path = '/'): void
    {
        $now = $this->now();
        $this->db->execute('UPDATE indexnow_settings SET host = ?, key_path = ?, key_verified_at = ?, last_error = NULL, updated_at = ? WHERE project_id = ?', [$host, $path, $now, $now, $projectId]);
    }

    public function saveSitemap(int $projectId, ?string $sitemapUrl, bool $autoSubmit): void
    {
        $this->db->execute(
            'UPDATE indexnow_settings SET sitemap_url = ?, auto_submit = ?, updated_at = ? WHERE project_id = ?',
            [$sitemapUrl, $autoSubmit ? 1 : 0, $this->now(), $projectId],
        );
    }

    public function recordSitemapCheck(int $projectId, ?string $error): void
    {
        $now = $this->now();
        $this->db->execute(
            'UPDATE indexnow_settings SET sitemap_checked_at = ?, last_error = ?, updated_at = ? WHERE project_id = ?',
            [$now, $error !== null ? mb_substr($error, 0, 500) : null, $now, $projectId],
        );
    }

    public function autoSubmitProjects(): array
    {
        return array_map(
            static fn(array $row): array => ['projectId' => (int) $row['project_id'], 'workspaceId' => (int) $row['workspace_id']],
            $this->db->fetchAll(
                'SELECT s.project_id, s.workspace_id FROM indexnow_settings s JOIN projects p ON p.id = s.project_id AND p.deleted_at IS NULL
                  WHERE s.auto_submit = 1 AND s.key_verified_at IS NOT NULL AND s.sitemap_url IS NOT NULL ORDER BY s.project_id',
            ),
        );
    }

    public function knownUrls(int $projectId): array
    {
        $urls = [];
        foreach ($this->db->fetchAll('SELECT url, lastmod FROM indexnow_urls WHERE project_id = ?', [$projectId]) as $row) {
            $urls[(string) $row['url']] = $row['lastmod'] !== null ? (string) $row['lastmod'] : null;
        }

        return $urls;
    }

    public function rememberUrls(int $projectId, array $urls, array $submitted): void
    {
        $now = $this->now();
        $submittedSet = array_flip($submitted);
        $this->db->transaction(function () use ($projectId, $urls, $submittedSet, $now): void {
            foreach (array_chunk($urls, self::INSERT_CHUNK) as $chunk) {
                $params = [];
                foreach ($chunk as $url) {
                    array_push($params, $projectId, hash('sha256', $url['loc'], true), $url['loc'], $url['lastmod'], $now, isset($submittedSet[$url['loc']]) ? $now : null);
                }
                $this->db->execute(
                    'INSERT INTO indexnow_urls (project_id, url_hash, url, lastmod, first_seen_at, submitted_at) VALUES '
                        . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)'))
                        . ' ON DUPLICATE KEY UPDATE lastmod = VALUES(lastmod), submitted_at = COALESCE(VALUES(submitted_at), submitted_at)',
                    $params,
                );
            }
        });
    }

    public function logSubmission(int $projectId, string $source, int $count, ?int $status, string $result, ?int $userId): void
    {
        $this->db->execute(
            'INSERT INTO indexnow_submissions (project_id, source, url_count, status_code, result, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$projectId, $source === 'sitemap' ? 'sitemap' : 'manual', $count, $status, mb_substr($result, 0, 500), $userId, $this->now()],
        );
    }

    public function submissions(int $projectId, int $limit): array
    {
        return array_map(
            static fn(array $row): array => [
                'source' => (string) $row['source'],
                'url_count' => (int) $row['url_count'],
                'status_code' => $row['status_code'] !== null ? (int) $row['status_code'] : null,
                'result' => (string) $row['result'],
                'created_at' => (string) $row['created_at'],
            ],
            $this->db->fetchAll('SELECT source, url_count, status_code, result, created_at FROM indexnow_submissions WHERE project_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$projectId]),
        );
    }

    public function delete(int $projectId): void
    {
        $this->db->execute('DELETE FROM indexnow_urls WHERE project_id = ?', [$projectId]);
        $this->db->execute('DELETE FROM indexnow_settings WHERE project_id = ?', [$projectId]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
