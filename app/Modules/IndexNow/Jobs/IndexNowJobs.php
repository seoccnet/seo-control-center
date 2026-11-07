<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\IndexNow\IndexNowScheduler;

/**
 * Queues IndexNow jobs. Sitemap checks collapse within 10 minutes per project.
 */
final readonly class IndexNowJobs implements IndexNowScheduler
{
    public const string SUBMIT = 'indexnow.submit';
    public const string SITEMAP = 'indexnow.sitemap';
    public const string SYNC = 'indexnow.sync';
    private const int WINDOW_SECONDS = 600;

    public function __construct(
        private Queue $queue,
        private Clock $clock,
    ) {}

    public function submit(int $projectId, int $workspaceId, array $urls, ?int $userId): void
    {
        $this->queue->dispatch(self::SUBMIT, ['project_id' => $projectId, 'urls' => array_values($urls), 'user_id' => $userId], workspaceId: $workspaceId);
    }

    public function checkSitemap(int $projectId, int $workspaceId): void
    {
        $window = intdiv($this->clock->now()->getTimestamp(), self::WINDOW_SECONDS);
        $this->queue->dispatch(self::SITEMAP, ['project_id' => $projectId], idempotencyKey: sprintf('indexnow.sitemap:%d:%d', $projectId, $window), workspaceId: $workspaceId);
    }
}
