<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing\Import;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Bing\BingImportScheduler;
use SeoControlCenter\Modules\Bing\Jobs\ImportBingJob;

/**
 * Queues Bing imports; the idempotency key collapses requests within 10 minutes.
 */
final readonly class QueuedBingImportScheduler implements BingImportScheduler
{
    private const int WINDOW_SECONDS = 600;

    public function __construct(
        private Queue $queue,
        private Clock $clock,
    ) {}

    public function requestImport(int $projectId, int $workspaceId): void
    {
        $window = intdiv($this->clock->now()->getTimestamp(), self::WINDOW_SECONDS);
        $this->queue->dispatch(
            ImportBingJob::TYPE,
            ['project_id' => $projectId, 'workspace_id' => $workspaceId],
            ImportBingJob::QUEUE,
            idempotencyKey: sprintf('bing.import:%d:%d', $projectId, $window),
            workspaceId: $workspaceId > 0 ? $workspaceId : null,
        );
    }
}
