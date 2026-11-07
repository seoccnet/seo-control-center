<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\Research\ResearchResult;
use SeoControlCenter\Modules\Research\ResearchService;

/**
 * Runs one queued research lookup (a single provider request).
 */
final readonly class FetchResearchJob implements JobHandler
{
    public function __construct(private ResearchService $service, private Connection $db) {}

    public function handle(array $payload, JobContext $context): void
    {
        $projectId = $payload['project_id'] ?? null;
        $workspaceId = $payload['workspace_id'] ?? null;
        $kind = $payload['kind'] ?? null;
        $subject = $payload['subject'] ?? null;
        if (!is_int($projectId) || !is_int($workspaceId) || !is_string($kind) || !in_array($kind, ResearchResult::KINDS, true) || !is_string($subject)) {
            throw new PermanentJobException('Invalid research payload.');
        }
        // Domain mentions can make 22 bounded requests (11 domains, two platforms).
        $lock = new LeaseLock($this->db, 'research.fetch:' . $projectId . ':' . $kind . ':' . hash('sha256', $subject), 1800);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another worker is processing this research lookup.');
        }
        try {
            $this->service->run($projectId, $workspaceId, $kind, $subject, $context->attempt >= $context->maxAttempts);
        } finally {
            $lock->release();
        }
    }
}
