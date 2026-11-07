<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Import;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\SearchConsole\ImportScheduler;
use SeoControlCenter\Modules\SearchConsole\Jobs\ImportSearchConsoleJob;

/**
 * Queues import jobs. The idempotency key covers a 10-minute window, so
 * double clicks and the scheduler never pile up duplicate jobs.
 */
final readonly class QueuedImportScheduler implements ImportScheduler
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
            ImportSearchConsoleJob::TYPE,
            ['project_id' => $projectId, 'workspace_id' => $workspaceId],
            ImportSearchConsoleJob::QUEUE,
            idempotencyKey: sprintf('search_console.import:%d:%d', $projectId, $window),
            workspaceId: $workspaceId > 0 ? $workspaceId : null,
        );
    }
}
