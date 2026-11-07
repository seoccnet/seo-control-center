<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors;

use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Domain\Rankings\SerpSnapshot;

/**
 * Records competitor positions from the SERPs rank checks already fetch,
 * so tracking competitors costs nothing extra.
 */
final class CompetitorTracker implements SerpObserver
{
    /** @var array<int, list<Competitor>> competitors per project, loaded once per run */
    private array $competitors = [];

    public function __construct(private readonly CompetitorRepository $repository) {}

    public function observe(int $projectId, int $projectKeywordId, string $date, SerpSnapshot $serp): void
    {
        $this->competitors[$projectId] ??= $this->repository->forProject($projectId);
        if ($this->competitors[$projectId] === []) {
            return;
        }

        $positions = [];
        foreach ($this->competitors[$projectId] as $competitor) {
            $found = null;
            foreach ($serp->organic as $result) {
                if (Competitor::owns($competitor->domain, $result->domain)) {
                    $found = $result;
                    break;
                }
            }
            $positions[] = ['competitor_id' => $competitor->id, 'position' => $found?->position, 'url' => $found?->url];
        }
        $this->repository->recordPositions($projectKeywordId, $serp->device, $date, $positions);
    }

    public function dayChecked(int $projectId, int $workspaceId, SerpDevice $device, string $date): void
    {
        $this->repository->aggregate($projectId, $workspaceId, $device, $date);
    }
}
