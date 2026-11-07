<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Projects\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectInput;
use SeoControlCenter\Domain\Projects\ProjectRepository;

final readonly class PdoProjectRepository implements ProjectRepository
{
    private const string COLUMNS = 'p.id, p.public_id, p.workspace_id, p.name, p.primary_domain, p.timezone, p.created_at, p.updated_at';

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function findInWorkspace(int $workspaceId, string $publicId): ?Project
    {
        if (!Uuid::isValid($publicId)) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM projects p WHERE p.workspace_id = ? AND p.public_id = ? AND p.deleted_at IS NULL',
            [$workspaceId, $publicId],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function allInWorkspace(int $workspaceId): array
    {
        return array_map($this->hydrate(...), $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM projects p WHERE p.workspace_id = ? AND p.deleted_at IS NULL ORDER BY p.name, p.id LIMIT 500',
            [$workspaceId],
        ));
    }

    public function memberProjectsInWorkspace(int $workspaceId, int $userId): array
    {
        return array_map($this->hydrate(...), $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . '
               FROM projects p
               JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
              WHERE p.workspace_id = ? AND p.deleted_at IS NULL
              ORDER BY p.name, p.id LIMIT 500',
            [$userId, $workspaceId],
        ));
    }

    public function create(int $workspaceId, ProjectInput $input, int $createdBy): Project
    {
        $now = $this->now();
        $publicId = Uuid::v7($this->clock);
        $this->db->execute(
            'INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$publicId, $workspaceId, $input->name, $input->primaryDomain->value, $input->timezone, $createdBy, $now, $now],
        );

        return new Project($this->db->lastInsertId(), $publicId, $workspaceId, $input->name, $input->primaryDomain->value, $input->timezone, $now, $now);
    }

    public function update(Project $project, ProjectInput $input): Project
    {
        $now = $this->now();
        $this->db->execute(
            'UPDATE projects SET name = ?, primary_domain = ?, timezone = ?, updated_at = ?
              WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL',
            [$input->name, $input->primaryDomain->value, $input->timezone, $now, $project->id, $project->workspaceId],
        );

        return new Project($project->id, $project->publicId, $project->workspaceId, $input->name, $input->primaryDomain->value, $input->timezone, $project->createdAt, $now);
    }

    public function softDelete(Project $project): void
    {
        $this->db->execute(
            'UPDATE projects SET deleted_at = ? WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL',
            [$this->now(), $project->id, $project->workspaceId],
        );
    }

    public function countInWorkspace(int $workspaceId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM projects WHERE workspace_id = ? AND deleted_at IS NULL', [$workspaceId]);
    }

    public function countAll(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Project
    {
        return new Project(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (string) $row['name'],
            (string) $row['primary_domain'],
            (string) $row['timezone'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
