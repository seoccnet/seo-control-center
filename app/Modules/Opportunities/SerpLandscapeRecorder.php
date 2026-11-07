<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities;

use SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Domain\Rankings\SerpSnapshot;

/**
 * Keeps the latest results page of each tracked keyword (top 10 with
 * titles, feature types, AI Overview flag) from the SERPs rank checks
 * already fetch, for the format, zero-click and rankability analysis. No
 * extra provider request and no history: one row per keyword and device.
 */
final readonly class SerpLandscapeRecorder implements SerpObserver
{
    public function __construct(private SerpLandscapeRepository $repository) {}

    public function observe(int $projectId, int $projectKeywordId, string $date, SerpSnapshot $serp): void
    {
        $this->repository->record($projectId, $projectKeywordId, $date, $serp);
    }

    public function dayChecked(int $projectId, int $workspaceId, SerpDevice $device, string $date): void {}
}
