<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

/**
 * Executes one job type. Handlers must be idempotent: a job may run again
 * after a crash or lease expiry. Long work should be split into bounded
 * chunks that re-dispatch a continuation job (QUEUE_AND_CRON.md).
 *
 * Throw PermanentJobException for failures that must not be retried
 * (invalid credentials, invalid input); any other exception is retried.
 */
interface JobHandler
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, JobContext $context): void;
}
