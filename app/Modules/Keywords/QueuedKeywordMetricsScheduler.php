<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Keywords;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Keywords\KeywordMetricsScheduler;
use SeoControlCenter\Modules\Keywords\Jobs\FetchKeywordMetricsJob;

/**
 * Queues metrics updates. The idempotency key covers a 5-minute window
 * (QUEUE_AND_CRON.md -> Idempotency), so double clicks never queue paid
 * work twice; a queued job always picks up every keyword still missing.
 */
final readonly class QueuedKeywordMetricsScheduler implements KeywordMetricsScheduler
{
    private const int WINDOW_SECONDS = 300;

    public function __construct(
        private Queue $queue,
        private Clock $clock,
    ) {}

    public function requestMetrics(int $projectId, int $workspaceId): void
    {
        $window = intdiv($this->clock->now()->getTimestamp(), self::WINDOW_SECONDS);
        $this->queue->dispatch(
            FetchKeywordMetricsJob::TYPE,
            ['project_id' => $projectId, 'workspace_id' => $workspaceId],
            FetchKeywordMetricsJob::QUEUE,
            idempotencyKey: sprintf('dfs:keyword_metrics:%d:%d', $projectId, $window),
            workspaceId: $workspaceId,
        );
    }
}
