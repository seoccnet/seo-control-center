<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks;

/**
 * Queues a backlink lookup.
 */
interface BacklinksScheduler
{
    public function fetch(int $projectId, int $workspaceId, string $kind): void;

    /** Queues a check of the pages the stored backlinks point to (1.5.0). */
    public function checkTargets(int $projectId, int $workspaceId): void;
}
