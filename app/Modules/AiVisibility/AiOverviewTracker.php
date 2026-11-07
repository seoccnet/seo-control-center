<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility;

use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Domain\Rankings\SerpSnapshot;

/**
 * Records the AI Overviews in the SERPs rank checks already fetch: whether
 * one was shown, its sources and where the project's own domain was cited.
 */
final class AiOverviewTracker implements SerpObserver
{
    /** @var array<int, ?string> project domain per project, loaded once per run */
    private array $domains = [];

    public function __construct(private readonly AiOverviewRepository $repository) {}

    public function observe(int $projectId, int $projectKeywordId, string $date, SerpSnapshot $serp): void
    {
        if (!array_key_exists($projectId, $this->domains)) {
            $this->domains[$projectId] = $this->repository->projectDomain($projectId);
        }
        $domain = $this->domains[$projectId];
        if ($domain === null) {
            return;
        }
        $overview = $serp->aiOverview;
        $this->repository->record($projectId, $projectKeywordId, $serp->device, $date, $overview, $overview?->positionOf($domain), $overview?->referenceFor($domain)?->url);
    }

    public function dayChecked(int $projectId, int $workspaceId, SerpDevice $device, string $date): void {}
}
