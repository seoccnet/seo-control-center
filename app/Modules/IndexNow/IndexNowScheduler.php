<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow;

/**
 * Queues IndexNow work: a list of URLs to submit, or a sitemap check.
 */
interface IndexNowScheduler
{
    /**
     * @param list<string> $urls
     */
    public function submit(int $projectId, int $workspaceId, array $urls, ?int $userId): void;

    public function checkSitemap(int $projectId, int $workspaceId): void;
}
