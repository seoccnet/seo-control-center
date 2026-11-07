<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks;

/**
 * Queues a backlink lookup.
 */
interface BacklinksScheduler
{
    public function fetch(int $projectId, int $workspaceId, string $kind): void;
}
