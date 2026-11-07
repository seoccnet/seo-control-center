<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;

final readonly class QueuedInspectionScheduler implements InspectionScheduler
{
    public function __construct(private Queue $queue, private Clock $clock) {}
    public function inspect(int $projectId, int $workspaceId, string $publicId, string $binding, string $url): void
    {
        $this->queue->dispatch('indexing.inspect', ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'public_id' => $publicId, 'binding' => $binding, 'url' => $url], 'imports', maxAttempts: 3, idempotencyKey: 'inspection:' . $projectId . ':' . hash('sha256', $binding . $url) . ':' . intdiv($this->clock->now()->getTimestamp(), 600), workspaceId: $workspaceId);
    }
}
