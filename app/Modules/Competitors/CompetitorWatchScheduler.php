<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors;

/** Queues competitor sitemap reads (1.6.0). */
interface CompetitorWatchScheduler
{
    public function check(int $competitorId, int $projectId, int $workspaceId, string $domain, string $today): void;
}
