<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

interface ProjectPropertyRepository
{
    public function linkFor(int $projectId): ?ProjectPropertyLink;

    public function link(int $projectId, int $connectionId, string $siteUrl, int $userId): void;

    public function unlink(int $projectId): void;

    /**
     * Removes every project link that uses the connection (on disconnect).
     */
    public function unlinkConnection(int $connectionId): int;

    /**
     * Links of all non-deleted projects, for scheduled imports.
     *
     * @return list<array{projectId: int, workspaceId: int}>
     */
    public function linkedProjects(): array;
}
