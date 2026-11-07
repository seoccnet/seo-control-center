<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Projects;

/**
 * Project persistence. Every lookup is scoped to a workspace: a project can
 * never be loaded by its ID alone (INSTRUCTIONS.md §5).
 */
interface ProjectRepository
{
    public function findInWorkspace(int $workspaceId, string $publicId): ?Project;

    /**
     * @return list<Project> all active projects of the workspace
     */
    public function allInWorkspace(int $workspaceId): array;

    /**
     * @return list<Project> active projects the user is a member of
     */
    public function memberProjectsInWorkspace(int $workspaceId, int $userId): array;

    public function create(int $workspaceId, ProjectInput $input, int $createdBy): Project;

    public function update(Project $project, ProjectInput $input): Project;

    public function softDelete(Project $project): void;

    public function countInWorkspace(int $workspaceId): int;

    /**
     * Active projects across the whole installation.
     */
    public function countAll(): int;
}
