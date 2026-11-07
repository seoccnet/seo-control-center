<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\Local\LocalService;

/**
 * Checks the points of a project's ranking grid. Holds a per-project lease
 * lock, stops before the cron run's time budget ends and continues.
 */
final readonly class GridLocalJob implements JobHandler
{
    private const int RESERVE_SECONDS = 40;
    private const int CONTINUE_AFTER_SECONDS = 30;

    public function __construct(
        private LocalService $service,
        private Connection $db,
        private Queue $queue,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $projectId = $payload['project_id'] ?? null;
        $workspaceId = $payload['workspace_id'] ?? null;
        if (!is_int($projectId) || $projectId < 1 || !is_int($workspaceId) || $workspaceId < 1) {
            throw new PermanentJobException('Invalid project_id or workspace_id.');
        }
        $lock = new LeaseLock($this->db, 'local.grid:' . $projectId, 180);
        if (!$lock->acquire()) {
            return;
        }
        try {
            $done = $this->service->runGrid(
                $projectId,
                static fn(): bool => $context->secondsRemaining() > self::RESERVE_SECONDS && $lock->renew() && $context->renewLease(),
                $context->attempt >= $context->maxAttempts,
            );
        } finally {
            $lock->release();
        }
        if (!$done) {
            $this->queue->dispatch(LocalJobs::GRID, $payload, LocalJobs::QUEUE, delaySeconds: self::CONTINUE_AFTER_SECONDS, workspaceId: $workspaceId);
        }
    }
}
