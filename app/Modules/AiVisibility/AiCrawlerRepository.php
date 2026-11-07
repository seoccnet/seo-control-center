<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility;

use SeoControlCenter\Domain\AiVisibility\AiCrawlerCheck;

/**
 * The latest AI crawler check of each project (0.43.0).
 */
interface AiCrawlerRepository
{
    public function latest(int $projectId): ?AiCrawlerCheck;

    /** Replaces the project's previous check. */
    public function save(int $workspaceId, AiCrawlerCheck $check): void;

    /**
     * Active projects never checked, or last checked before $checkedBefore, oldest first.
     *
     * @return list<array{project_id: int, workspace_id: int, project_public_id: string}>
     */
    public function dueProjects(string $checkedBefore, int $limit): array;
}
