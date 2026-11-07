<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * Requests a background Search Console import for a project.
 * Repeated requests within a short window collapse into one job.
 */
interface ImportScheduler
{
    public function requestImport(int $projectId, int $workspaceId): void;
}
