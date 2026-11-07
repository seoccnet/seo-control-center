<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SerpHistory;

use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Domain\Rankings\SerpSnapshot;

/**
 * Records results-page changes right after a rank check day was stored
 * (also after a partial day; detection is idempotent).
 */
final readonly class SerpHistoryRecorder implements SerpObserver
{
    public function __construct(private SerpHistoryService $history) {}

    public function observe(int $projectId, int $projectKeywordId, string $date, SerpSnapshot $serp): void {}

    public function dayChecked(int $projectId, int $workspaceId, SerpDevice $device, string $date): void
    {
        $this->history->detectDay($projectId, $workspaceId, $device, $date);
    }
}
