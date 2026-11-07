<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\Backlinks\BacklinksService;

/**
 * Runs one queued backlink lookup.
 */
final readonly class FetchBacklinksJob implements JobHandler
{
    public function __construct(private BacklinksService $service, private Connection $db) {}

    public function handle(array $payload, JobContext $context): void
    {
        $projectId = $payload['project_id'] ?? null;
        $workspaceId = $payload['workspace_id'] ?? null;
        $kind = $payload['kind'] ?? null;
        if (!is_int($projectId) || !is_int($workspaceId) || !is_string($kind) || !in_array($kind, BacklinksService::KINDS, true)) {
            throw new PermanentJobException('Invalid backlinks payload.');
        }
        $lock = new LeaseLock($this->db, 'backlinks.fetch:' . $projectId . ':' . $kind, 600);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another worker is processing this backlink lookup.');
        }
        try {
            $this->service->run($projectId, $workspaceId, $kind, $context->attempt >= $context->maxAttempts);
        } finally {
            $lock->release();
        }
    }
}
