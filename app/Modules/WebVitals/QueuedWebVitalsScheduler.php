<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\WebVitals;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;

final readonly class QueuedWebVitalsScheduler implements WebVitalsScheduler
{
    public function __construct(private Queue $queue, private Clock $clock) {}
    public function fetch(int $projectId, int $workspaceId, string $projectPublicId, string $url, string $formFactor = 'PHONE'): void
    {
        $this->queue->dispatch(
            'web_vitals.fetch',
            ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'project_public_id' => $projectPublicId, 'url' => $url, 'form_factor' => $formFactor],
            'imports',
            idempotencyKey: 'crux:' . $projectId . ':' . hash('sha256', $url) . ':' . $formFactor . ':' . (int) ($this->clock->now()->getTimestamp() / 600),
            workspaceId: $workspaceId
        );
    }
}
