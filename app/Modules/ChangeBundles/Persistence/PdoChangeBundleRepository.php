<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ChangeBundles\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\ChangeBundles\ChangeBundleRepository;

final readonly class PdoChangeBundleRepository implements ChangeBundleRepository
{
    public function __construct(private Connection $db) {}

    public function list(Project $project, int $limit): array
    {
        return array_map(self::row(...), $this->db->fetchAll('SELECT * FROM change_bundles WHERE workspace_id = ? AND project_id = ? ORDER BY updated_at DESC, id DESC LIMIT ' . max(1, min(200, $limit)), [$project->workspaceId, $project->id]));
    }

    public function find(Project $project, string $publicId): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM change_bundles WHERE workspace_id = ? AND project_id = ? AND public_id = ?', [$project->workspaceId, $project->id, $publicId]);

        return $row === null ? null : self::row($row);
    }

    public function countInProject(int $projectId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM change_bundles WHERE project_id = ?', [$projectId]);
    }

    public function create(Project $project, string $publicId, string $title, string $url, string $status, array $data, ?int $userId, string $now): void
    {
        $this->db->execute('INSERT INTO change_bundles (public_id, workspace_id, project_id, title, url, status, data, version, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)', [$publicId, $project->workspaceId, $project->id, $title, $url, $status, self::json($data), $userId, $now, $now]);
    }

    public function update(Project $project, string $publicId, string $title, string $url, string $status, array $data, int $expectedVersion, string $now): bool
    {
        return $this->db->execute('UPDATE change_bundles SET title = ?, url = ?, status = ?, data = ?, version = version + 1, updated_at = ? WHERE workspace_id = ? AND project_id = ? AND public_id = ? AND version = ?', [$title, $url, $status, self::json($data), $now, $project->workspaceId, $project->id, $publicId, $expectedVersion]) === 1;
    }

    public function delete(Project $project, string $publicId, int $expectedVersion): bool
    {
        return $this->db->execute('DELETE FROM change_bundles WHERE workspace_id = ? AND project_id = ? AND public_id = ? AND version = ?', [$project->workspaceId, $project->id, $publicId, $expectedVersion]) === 1;
    }

    public function addEvent(Project $project, string $publicId, string $kind, string $detail, ?int $userId, string $now): void
    {
        $this->db->execute('INSERT INTO change_bundle_events (bundle_id, kind, detail, user_id, created_at) SELECT id, ?, ?, ?, ? FROM change_bundles WHERE workspace_id = ? AND project_id = ? AND public_id = ?', [$kind, mb_substr($detail, 0, 2000), $userId, $now, $project->workspaceId, $project->id, $publicId]);
    }

    public function events(Project $project, string $publicId): array
    {
        return array_map(static fn(array $r): array => ['kind' => (string) $r['kind'], 'detail' => (string) $r['detail'], 'created_at' => (string) $r['created_at']], $this->db->fetchAll('SELECT e.kind, e.detail, e.created_at FROM change_bundle_events e JOIN change_bundles b ON b.id = e.bundle_id WHERE b.workspace_id = ? AND b.project_id = ? AND b.public_id = ? ORDER BY e.id DESC LIMIT 100', [$project->workspaceId, $project->id, $publicId]));
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{public_id: string, title: string, url: string, status: string, version: int, created_at: string, updated_at: string, data: array<string, mixed>}
     */
    private static function row(array $row): array
    {
        $data = json_decode((string) $row['data'], true);

        return ['public_id' => (string) $row['public_id'], 'title' => (string) $row['title'], 'url' => (string) $row['url'], 'status' => (string) $row['status'], 'version' => (int) $row['version'], 'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at'], 'data' => is_array($data) ? $data : []];
    }
}
