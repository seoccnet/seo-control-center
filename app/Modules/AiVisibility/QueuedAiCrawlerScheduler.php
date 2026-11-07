<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;

final readonly class QueuedAiCrawlerScheduler implements AiCrawlerScheduler
{
    public const string JOB = 'ai_crawlers.check';

    public function __construct(private Queue $queue, private Clock $clock) {}

    public function check(int $projectId, int $workspaceId, string $publicId): void
    {
        $this->queue->dispatch(
            self::JOB,
            ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'project_public_id' => $publicId],
            'default',
            maxAttempts: 2,
            idempotencyKey: 'ai-crawlers:' . $projectId . ':' . (int) ($this->clock->now()->getTimestamp() / 600),
            workspaceId: $workspaceId,
        );
    }
}
