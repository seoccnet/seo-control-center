<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * Gets every SERP a rank check fetched, so other features (competitors)
 * can use the full result list without paying for another request.
 */
interface SerpObserver
{
    public function observe(int $projectId, int $projectKeywordId, string $date, SerpSnapshot $serp): void;

    /**
     * Called after a check day's own aggregate was updated (also for partial days).
     */
    public function dayChecked(int $projectId, int $workspaceId, SerpDevice $device, string $date): void;
}
