<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\SiteHealth\SiteHealthRepository;
use SeoControlCenter\Modules\SiteHealth\SiteHealthState;

final readonly class PdoSiteHealthRepository implements SiteHealthRepository
{
    /** State column => SiteHealthState property. */
    private const array COLUMNS = [
        'enabled' => 'enabled', 'uptime_status' => 'uptimeStatus', 'uptime_code' => 'uptimeCode', 'uptime_ms' => 'uptimeMs', 'uptime_error' => 'uptimeError',
        'failures' => 'failures', 'down_since' => 'downSince', 'uptime_checked_at' => 'uptimeCheckedAt', 'ssl_expires_at' => 'sslExpiresAt', 'ssl_issuer' => 'sslIssuer',
        'ssl_error' => 'sslError', 'domain_name' => 'domainName', 'domain_expires_at' => 'domainExpiresAt', 'domain_registrar' => 'domainRegistrar', 'domain_error' => 'domainError',
        'domain_checked_at' => 'domainCheckedAt', 'robots_status' => 'robotsStatus', 'robots_hash' => 'robotsHash', 'robots_text' => 'robotsText',
        'robots_changed_at' => 'robotsChangedAt', 'sitemap_urls' => 'sitemapUrls', 'sitemap_changed_at' => 'sitemapChangedAt', 'daily_checked_at' => 'dailyCheckedAt',
    ];

    public function __construct(private Connection $db, private Clock $clock) {}

    public function state(int $projectId): SiteHealthState
    {
        $row = $this->db->fetchOne('SELECT * FROM site_health_state WHERE project_id = ?', [$projectId]);
        if ($row === null) {
            return new SiteHealthState($projectId);
        }
        $str = static fn(string $c): ?string => isset($row[$c]) ? (string) $row[$c] : null;
        $int = static fn(string $c): ?int => isset($row[$c]) ? (int) $row[$c] : null;

        return new SiteHealthState(
            $projectId,
            (bool) $row['enabled'],
            $str('uptime_status'),
            $int('uptime_code'),
            $int('uptime_ms'),
            $str('uptime_error'),
            (int) $row['failures'],
            $str('down_since'),
            $str('uptime_checked_at'),
            $str('ssl_expires_at'),
            $str('ssl_issuer'),
            $str('ssl_error'),
            $str('domain_name'),
            $str('domain_expires_at'),
            $str('domain_registrar'),
            $str('domain_error'),
            $str('domain_checked_at'),
            $int('robots_status'),
            $str('robots_hash'),
            $str('robots_text'),
            $str('robots_changed_at'),
            $int('sitemap_urls'),
            $str('sitemap_changed_at'),
            $str('daily_checked_at'),
        );
    }

    public function save(int $workspaceId, SiteHealthState $state, string $now): void
    {
        $values = get_object_vars($state);
        $columns = array_keys(self::COLUMNS);
        $params = [$state->projectId, $workspaceId];
        foreach (self::COLUMNS as $property) {
            $value = $values[$property];
            $params[] = is_bool($value) ? (int) $value : $value;
        }
        $params[] = $now;
        $this->db->execute(
            'INSERT INTO site_health_state (project_id, workspace_id, ' . implode(', ', $columns) . ', updated_at) VALUES (' . implode(', ', array_fill(0, count($columns) + 3, '?')) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn(string $c): string => $c . ' = VALUES(' . $c . ')', [...$columns, 'updated_at'])),
            $params,
        );
    }

    public function setEnabled(int $projectId, int $workspaceId, bool $enabled, string $now): void
    {
        $this->db->execute(
            'INSERT INTO site_health_state (project_id, workspace_id, enabled, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = VALUES(updated_at)',
            [$projectId, $workspaceId, $enabled ? 1 : 0, $now],
        );
    }

    public function due(string $kind, string $before, int $limit): array
    {
        $column = $kind === 'uptime' ? 's.uptime_checked_at' : 's.daily_checked_at';
        $rows = $this->db->fetchAll(
            'SELECT p.id, p.workspace_id, p.primary_domain FROM projects p JOIN workspaces w ON w.id = p.workspace_id AND w.deleted_at IS NULL
             LEFT JOIN site_health_state s ON s.project_id = p.id
             WHERE p.deleted_at IS NULL AND (s.project_id IS NULL OR (s.enabled = 1 AND (' . $column . ' IS NULL OR ' . $column . ' < ?)))
             ORDER BY ' . $column . ' IS NOT NULL, ' . $column . ', p.id LIMIT ' . max(1, $limit),
            [$before],
        );

        return array_map(static fn(array $r): array => ['projectId' => (int) $r['id'], 'workspaceId' => (int) $r['workspace_id'], 'domain' => (string) $r['primary_domain']], $rows);
    }

    public function addEvent(int $projectId, string $kind, string $key, string $title, string $detail, string $now): bool
    {
        return $this->db->execute(
            'INSERT IGNORE INTO site_health_events (public_id, project_id, kind, dedupe_key, title, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [Uuid::v7($this->clock), $projectId, $kind, mb_substr($key, 0, 120), mb_substr($title, 0, 200), mb_substr($detail, 0, 2000), $now],
        ) > 0;
    }

    public function events(int $projectId, ?string $since, int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT public_id, kind, title, detail, created_at FROM site_health_events WHERE project_id = ?' . ($since !== null ? ' AND created_at >= ?' : '') . ' ORDER BY id DESC LIMIT ' . max(1, $limit),
            $since !== null ? [$projectId, $since] : [$projectId],
        );

        return array_map(static fn(array $r): array => ['id' => (string) $r['public_id'], 'kind' => (string) $r['kind'], 'title' => (string) $r['title'], 'detail' => (string) $r['detail'], 'created_at' => (string) $r['created_at']], $rows);
    }

    public function pruneEvents(int $keep): void
    {
        foreach ($this->db->fetchAll('SELECT project_id FROM site_health_events GROUP BY project_id HAVING COUNT(*) > ?', [$keep]) as $row) {
            $cutoff = $this->db->fetchValue('SELECT id FROM site_health_events WHERE project_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . max(0, $keep - 1), [(int) $row['project_id']]);
            if ($cutoff !== null) {
                $this->db->execute('DELETE FROM site_health_events WHERE project_id = ? AND id < ?', [(int) $row['project_id'], (int) $cutoff]);
            }
        }
    }
}
