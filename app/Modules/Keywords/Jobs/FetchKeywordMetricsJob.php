<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Keywords\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\Keywords\KeywordMetricsUpdater;

/**
 * Updates keyword metrics for one project. Holds a per-project lease lock,
 * stops before the cron run's time budget ends and queues a continuation.
 * Budget and permanent provider errors fail the job without retries.
 */
final readonly class FetchKeywordMetricsJob implements JobHandler
{
    public const string TYPE = 'keywords.metrics';
    public const string QUEUE = 'analysis';
    /** Seconds kept free for one provider request (up to 30 s) and storing. */
    private const int RESERVE_SECONDS = 40;
    private const int CONTINUE_AFTER_SECONDS = 30;

    public function __construct(
        private KeywordMetricsUpdater $updater,
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

        $lock = new LeaseLock($this->db, 'keywords.metrics:' . $projectId, 180);
        if (!$lock->acquire()) {
            return; // Another run is updating this project.
        }
        try {
            $done = $this->updater->run(
                $projectId,
                $workspaceId,
                static fn(): bool => $context->secondsRemaining() > self::RESERVE_SECONDS && $lock->renew() && $context->renewLease(),
            );
        } catch (BudgetExceededException $e) {
            throw new PermanentJobException($e->getMessage(), 0, $e);
        } catch (IntegrationException $e) {
            if ($e->retryable) {
                throw $e;
            }
            throw new PermanentJobException($e->getMessage(), 0, $e);
        } finally {
            $lock->release();
        }

        if (!$done) {
            // Continue in the next cron run, not in the few seconds left of this one.
            $this->queue->dispatch(self::TYPE, $payload, self::QUEUE, delaySeconds: self::CONTINUE_AFTER_SECONDS, workspaceId: $workspaceId);
        }
    }
}
