<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;

final readonly class QueuedGa4Scheduler implements Ga4Scheduler
{
    public function __construct(private Queue $queue, private Clock $clock) {}
    public function import(int $projectId, int $workspaceId, string $publicId, int $days = 28): void
    {
        $this->queue->dispatch(
            'ga4.import',
            ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'project_public_id' => $publicId, 'days' => $days],
            'imports',
            idempotencyKey: 'ga4:' . $projectId . ':' . $days . ':' . (int) ($this->clock->now()->getTimestamp() / 600),
            workspaceId: $workspaceId
        );
    }

    public function check(string $checkId, int $workspaceId): void
    {
        $this->queue->dispatch(BusinessMeasurementService::CHECK_JOB, ['check_id' => $checkId], 'imports', maxAttempts: 1, idempotencyKey: 'ga4-check:' . $checkId, workspaceId: $workspaceId);
    }
}
