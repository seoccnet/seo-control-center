<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\Backlinks\LinkReclamationService;

/**
 * Checks the project pages that backlinks point to (1.5.0), resuming where
 * the previous run of the same check stopped.
 */
final readonly class CheckLinkTargetsJob implements JobHandler
{
    public function __construct(private LinkReclamationService $service, private Connection $db) {}

    public function handle(array $payload, JobContext $context): void
    {
        $projectId = $payload['project_id'] ?? null;
        $workspaceId = $payload['workspace_id'] ?? null;
        if (!is_int($projectId) || !is_int($workspaceId)) {
            throw new PermanentJobException('Invalid link target payload.');
        }
        $lock = new LeaseLock($this->db, 'backlinks.targets:' . $projectId, 600);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another worker is checking these linked pages.');
        }
        try {
            $this->service->run($projectId, $workspaceId, $context->secondsRemaining(...), $context->renewLease(...));
        } catch (\Throwable $e) {
            if ($context->attempt >= $context->maxAttempts || $e instanceof PermanentJobException) {
                $this->service->fail($projectId, $workspaceId);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
