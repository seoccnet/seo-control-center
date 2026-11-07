<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Local\LocationScheduler;

final readonly class LocationJobs implements LocationScheduler
{
    public const string RUN = 'local.location';
    public const string SCHEDULE = 'local.locations.schedule';
    public function __construct(private Queue $queue, private Clock $clock) {}
    public function dispatch(string $mode, int $projectId, int $workspaceId, string $projectPublicId, string $location, int $delay = 0, ?string $gridId = null): void
    {
        $this->queue->dispatch(self::RUN, ['mode' => $mode, 'project_id' => $projectId, 'workspace_id' => $workspaceId, 'public_id' => $projectPublicId, 'location' => $location, 'grid' => $gridId], queue: 'analysis', delaySeconds: $delay, maxAttempts: $mode === 'reviews' ? 1 : 3, idempotencyKey: $delay === 0 && $mode === 'check' ? 'local.location:' . $mode . ':' . $projectId . ':' . $location . ':' . intdiv($this->clock->now()->getTimestamp(), 600) : null, workspaceId: $workspaceId);
    }
}
