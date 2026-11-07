<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyLink;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;

final readonly class PdoProjectPropertyRepository implements ProjectPropertyRepository
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function linkFor(int $projectId): ?ProjectPropertyLink
    {
        $row = $this->db->fetchOne('SELECT project_id, connection_id, site_url, linked_at FROM project_search_console WHERE project_id = ?', [$projectId]);

        return $row === null ? null : new ProjectPropertyLink((int) $row['project_id'], (int) $row['connection_id'], (string) $row['site_url'], (string) $row['linked_at']);
    }

    public function link(int $projectId, int $connectionId, string $siteUrl, int $userId): void
    {
        $this->db->execute(
            'INSERT INTO project_search_console (project_id, connection_id, site_url, linked_by, linked_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE connection_id = VALUES(connection_id), site_url = VALUES(site_url),
                 linked_by = VALUES(linked_by), linked_at = VALUES(linked_at)',
            [$projectId, $connectionId, $siteUrl, $userId, $this->clock->now()->format('Y-m-d H:i:s')],
        );
    }

    public function unlink(int $projectId): void
    {
        $this->db->execute('DELETE FROM project_search_console WHERE project_id = ?', [$projectId]);
    }

    public function unlinkConnection(int $connectionId): int
    {
        return $this->db->execute('DELETE FROM project_search_console WHERE connection_id = ?', [$connectionId]);
    }

    public function linkedProjects(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT l.project_id, p.workspace_id FROM project_search_console l
             JOIN projects p ON p.id = l.project_id AND p.deleted_at IS NULL
             ORDER BY l.project_id',
        );

        return array_map(static fn(array $r): array => ['projectId' => (int) $r['project_id'], 'workspaceId' => (int) $r['workspace_id']], $rows);
    }
}
