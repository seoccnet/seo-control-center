<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\Local\LocalScheduler;

/**
 * Queues local checks on the rankings queue.
 */
final readonly class LocalJobs implements LocalScheduler
{
    public const string CHECK = 'local.check';
    public const string SCHEDULE = 'local.schedule';
    public const string GRID = 'local.grid';
    public const string QUEUE = 'rankings';

    public function __construct(private Queue $queue) {}

    public function check(int $projectId, int $workspaceId): void
    {
        $this->queue->dispatch(self::CHECK, ['project_id' => $projectId, 'workspace_id' => $workspaceId], self::QUEUE, workspaceId: $workspaceId);
    }

    public function grid(int $projectId, int $workspaceId): void
    {
        $this->queue->dispatch(self::GRID, ['project_id' => $projectId, 'workspace_id' => $workspaceId], self::QUEUE, workspaceId: $workspaceId);
    }
}
