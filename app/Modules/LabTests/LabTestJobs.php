<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\LabTests;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Queue\Queue;

/**
 * lab_tests.run works on one run and queues its next step (a delayed retry
 * or a DataForSEO collection); lab_tests.schedule queues due weekly runs.
 * ADR-040, F2.
 */
final readonly class LabTestJobs implements LabTestScheduler, JobHandler
{
    public const string RUN = 'lab_tests.run';
    public const string SCHEDULE = 'lab_tests.schedule';
    public const string QUEUE = 'analysis';

    public function __construct(private Queue $queue, private ?LabTestService $service = null) {}

    public function queue(int $runId, int $workspaceId, int $delaySeconds = 0): void
    {
        $this->queue->dispatch(self::RUN, ['run_id' => $runId, 'workspace_id' => $workspaceId], self::QUEUE, delaySeconds: $delaySeconds, maxAttempts: 1, workspaceId: $workspaceId);
    }

    public function handle(array $payload, JobContext $context): void
    {
        if ($this->service === null) {
            return;
        }
        if ($context->type === self::SCHEDULE) {
            $this->service->schedule();

            return;
        }
        $runId = $payload['run_id'] ?? null;
        $workspaceId = $payload['workspace_id'] ?? null;
        if (!is_int($runId) || $runId < 1 || !is_int($workspaceId)) {
            throw new PermanentJobException('Invalid lab run.');
        }
        $next = $this->service->execute($runId);
        if ($next !== null) {
            $this->queue($runId, $workspaceId, $next);
        }
    }
}
