<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteMigration\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\SiteMigration\SiteMigrationService;

/**
 * Suggests redirects or tests them for one site migration plan. Tests
 * resume from the plan's saved cursor; after the final attempt the plan's
 * task is marked failed.
 */
final readonly class RunSiteMigrationJob implements JobHandler
{
    public function __construct(private SiteMigrationService $migrations) {}

    public function handle(array $payload, JobContext $context): void
    {
        $id = $payload['migration_id'] ?? null;
        $task = $payload['task'] ?? null;
        if (!is_int($id) || !in_array($task, ['match', 'test'], true)) {
            throw new PermanentJobException('Invalid site migration payload.');
        }
        try {
            if ($task === 'match') {
                $this->migrations->runMatch($id);
            } else {
                $this->migrations->runTest($id, $context->secondsRemaining(...), $context->renewLease(...));
            }
        } catch (\Throwable $e) {
            if ($context->attempt >= $context->maxAttempts || $e instanceof PermanentJobException) {
                $this->migrations->fail($id, $task, $task === 'match' ? 'Suggesting redirects failed. Try again.' : 'The redirect test stopped. Run it again.');
            }
            throw $e;
        }
    }
}
