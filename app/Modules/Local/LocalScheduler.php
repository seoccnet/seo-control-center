<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local;

/**
 * Queues a project's local check.
 */
interface LocalScheduler
{
    public function check(int $projectId, int $workspaceId): void;

    public function grid(int $projectId, int $workspaceId): void;
}
