<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing;

/**
 * Queues a Bing import for a project (implementations collapse duplicates).
 */
interface BingImportScheduler
{
    public function requestImport(int $projectId, int $workspaceId): void;
}
