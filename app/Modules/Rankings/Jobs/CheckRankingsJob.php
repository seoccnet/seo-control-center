<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\Rankings\RankChecker;

/**
 * Checks one project's rankings for one day (payload `date`, UTC), so a run
 * that crosses midnight stays on its day. Per-project lease lock,
 * continuation when the cron run's time is used up.
 */
final readonly class CheckRankingsJob implements JobHandler
{
    public const string TYPE = 'rankings.check';
    public const string QUEUE = 'rankings';
    /** Seconds kept free for one SERP request (up to 30 s) and storing. */
    private const int RESERVE_SECONDS = 35;
    private const int CONTINUE_AFTER_SECONDS = 30;

    public function __construct(
        private RankChecker $checker,
        private Connection $db,
        private Queue $queue,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $projectId = $payload['project_id'] ?? null;
        $workspaceId = $payload['workspace_id'] ?? null;
        $date = $payload['date'] ?? null;
        if (!is_int($projectId) || $projectId < 1 || !is_int($workspaceId) || $workspaceId < 1
            || !is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            throw new PermanentJobException('Invalid project_id, workspace_id or date.');
        }

        $lock = new LeaseLock($this->db, 'rankings.check:' . $projectId, 180);
        if (!$lock->acquire()) {
            return; // Another run is checking this project.
        }
        try {
            $done = $this->checker->run(
                $projectId,
                $workspaceId,
                $date,
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
