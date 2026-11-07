<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\IndexNow\IndexNowService;

/**
 * Checks one project's sitemap and submits new and changed URLs.
 */
final readonly class SitemapIndexNowJob implements JobHandler
{
    public function __construct(
        private IndexNowService $service,
        private Connection $db,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $projectId = $payload['project_id'] ?? null;
        if (!is_int($projectId) || $projectId < 1) {
            throw new PermanentJobException('Invalid project_id.');
        }
        $lock = new LeaseLock($this->db, 'indexnow.sitemap:' . $projectId, 300);
        if (!$lock->acquire()) {
            return;
        }
        try {
            $this->service->runSitemap($projectId);
        } finally {
            $lock->release();
        }
    }
}
