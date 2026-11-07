<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\SearchConsole\Import\SearchConsoleImporter;

/**
 * Imports Search Console data for one project until the cron run's time
 * budget is nearly used, then queues a continuation for the rest.
 * A per-project lock keeps two runs from importing the same project.
 */
final readonly class ImportSearchConsoleJob implements JobHandler
{
    public const string TYPE = 'search_console.import';
    public const string QUEUE = 'imports';
    /** Seconds kept free at the end of a run for storing and bookkeeping. */
    private const int RESERVE_SECONDS = 15;
    private const int CONTINUE_AFTER_SECONDS = 30;

    public function __construct(
        private SearchConsoleImporter $importer,
        private Connection $db,
        private Queue $queue,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $projectId = $payload['project_id'] ?? null;
        if (!is_int($projectId) || $projectId < 1) {
            throw new PermanentJobException('Invalid project_id.');
        }

        $lock = new LeaseLock($this->db, 'search_console.import:' . $projectId, 180);
        if (!$lock->acquire()) {
            return; // Another run is importing this project and will continue.
        }
        try {
            $outcome = $this->importer->run(
                $projectId,
                static fn(): bool => $context->secondsRemaining() > self::RESERVE_SECONDS && $lock->renew() && $context->renewLease(),
            );
        } catch (IntegrationException $e) {
            if ($e->retryable) {
                throw $e;
            }
            throw new PermanentJobException($e->getMessage(), 0, $e);
        } finally {
            $lock->release();
        }

        if ($outcome->daysRemaining > 0) {
            // Continue in the next cron run, not in the few seconds left of this one.
            $this->queue->dispatch(self::TYPE, ['project_id' => $projectId], self::QUEUE, delaySeconds: self::CONTINUE_AFTER_SECONDS, workspaceId: is_int($payload['workspace_id'] ?? null) ? $payload['workspace_id'] : null);
        }
    }
}
