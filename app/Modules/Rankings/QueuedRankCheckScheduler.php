<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Rankings\RankCheckScheduler;
use SeoControlCenter\Modules\Rankings\Jobs\CheckRankingsJob;

/**
 * Queues rank checks with an idempotency key per project, day and
 * 10-minute window. Paying twice is impossible anyway: a check only
 * requests keywords without a snapshot for that day.
 */
final readonly class QueuedRankCheckScheduler implements RankCheckScheduler
{
    private const int WINDOW_SECONDS = 600;

    public function __construct(
        private Queue $queue,
        private Clock $clock,
    ) {}

    public function requestCheck(int $projectId, int $workspaceId, string $date): void
    {
        $window = intdiv($this->clock->now()->getTimestamp(), self::WINDOW_SECONDS);
        $this->queue->dispatch(
            CheckRankingsJob::TYPE,
            ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'date' => $date],
            CheckRankingsJob::QUEUE,
            idempotencyKey: sprintf('dfs:rankings:%d:%s:%d', $projectId, $date, $window),
            workspaceId: $workspaceId,
        );
    }
}
