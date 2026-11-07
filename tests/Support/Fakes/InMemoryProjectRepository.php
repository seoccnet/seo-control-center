<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectInput;
use SeoControlCenter\Domain\Projects\ProjectRepository;

final readonly class InMemoryProjectRepository implements ProjectRepository
{
    public function __construct(private InMemoryDirectory $dir) {}

    public function findInWorkspace(int $workspaceId, string $publicId): ?Project
    {
        foreach ($this->dir->projects as $project) {
            if ($project->workspaceId === $workspaceId && $project->publicId === $publicId && !isset($this->dir->deleted[$project->id])) {
                return $project;
            }
        }

        return null;
    }

    public function allInWorkspace(int $workspaceId): array
    {
        return array_values(array_filter(
            $this->dir->projects,
            fn(Project $p): bool => $p->workspaceId === $workspaceId && !isset($this->dir->deleted[$p->id]),
        ));
    }

    public function memberProjectsInWorkspace(int $workspaceId, int $userId): array
    {
        return array_values(array_filter(
            $this->allInWorkspace($workspaceId),
            fn(Project $p): bool => array_key_exists($userId, $this->dir->projectRoles[$p->id] ?? []),
        ));
    }

    public function create(int $workspaceId, ProjectInput $input, int $createdBy): Project
    {
        $id = $this->dir->nextId();
        $project = new Project($id, Uuid::v7(), $workspaceId, $input->name, $input->primaryDomain->value, $input->timezone, '2026-09-26 10:00:00', '2026-09-26 10:00:00');
        $this->dir->projects[$id] = $project;

        return $project;
    }

    public function update(Project $project, ProjectInput $input): Project
    {
        $updated = new Project($project->id, $project->publicId, $project->workspaceId, $input->name, $input->primaryDomain->value, $input->timezone, $project->createdAt, '2026-09-26 11:00:00');
        $this->dir->projects[$project->id] = $updated;

        return $updated;
    }

    public function softDelete(Project $project): void
    {
        $this->dir->deleted[$project->id] = true;
    }

    public function countInWorkspace(int $workspaceId): int
    {
        return count($this->allInWorkspace($workspaceId));
    }

    public function countAll(): int
    {
        return count(array_filter($this->dir->projects, fn(Project $p): bool => !isset($this->dir->deleted[$p->id])));
    }
}
