<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\IndexNow\IndexNowRepository;
use SeoControlCenter\Modules\IndexNow\IndexNowScheduler;

/**
 * Scheduled: queues a sitemap check for every project with automatic submissions.
 */
final readonly class SyncIndexNowJob implements JobHandler
{
    public function __construct(
        private IndexNowRepository $repository,
        private IndexNowScheduler $scheduler,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        foreach ($this->repository->autoSubmitProjects() as $project) {
            $this->scheduler->checkSitemap($project['projectId'], $project['workspaceId']);
        }
    }
}
