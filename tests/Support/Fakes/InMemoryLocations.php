<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Modules\Local\LocalLocation;
use SeoControlCenter\Modules\Local\LocationRepository;
use SeoControlCenter\Modules\Local\LocationScheduler;

final class InMemoryLocations implements LocationRepository, LocationScheduler
{
    /** @var array<int, array<string, LocalLocation>> */ private array $locations = [];
    /** @var array<int, array<string, array<string, array<string, mixed>>>> */ private array $checks = [];
    /** @var array<int, array<string, array<string, mixed>>> */ private array $reviews = [];
    /** @var list<array{mode: string, project: int, workspace: int, public: string, location: string, delay: int}> */ public array $jobs = [];
    /** @var list<array{project_id: int, workspace_id: int, public_id: string, location: string}> */ public array $dueRows = [];
    /** @var array<int, array<string, array<string, \SeoControlCenter\Modules\Local\BranchGrid>>> */
    private array $grids = [];
    public function grids(int $projectId, string $location): array
    {
        $grids = $this->grids[$projectId][$location] ?? [];
        krsort($grids);
        return array_slice(array_values($grids), 0, 12);
    }
    public function queuedGrid(int $projectId, string $location): ?\SeoControlCenter\Modules\Local\BranchGrid
    {
        foreach ($this->grids($projectId, $location) as $grid) {
            if ($grid->grid->status === 'queued') {
                return $grid;
            }
        }
        return null;
    }
    public function createGrid(int $projectId, string $location, \SeoControlCenter\Modules\Local\BranchGrid $grid): void
    {
        if ($this->queuedGrid($projectId, $location) !== null) {
            throw new \SeoControlCenter\Core\Http\Exception\HttpException(409, 'A grid is already queued for this branch.');
        }
        $this->grids[$projectId][$location][$grid->publicId] = $grid;
    }
    public function saveGridPoint(int $projectId, string $publicId, int $index, ?int $position): void
    {
        foreach ($this->grids[$projectId] ?? [] as $location => $grids) {
            if (isset($grids[$publicId])) {
                $this->grids[$projectId][$location][$publicId] = $grids[$publicId]->withPoint($index, $position);
            }
        }
    }
    public function finishGrid(int $projectId, string $publicId, string $status, ?string $error = null): void
    {
        foreach ($this->grids[$projectId] ?? [] as $location => $grids) {
            if (isset($grids[$publicId])) {
                $this->grids[$projectId][$location][$publicId] = $grids[$publicId]->withStatus($status, $error);
            }
        }
    }
    public function locations(int $projectId): array
    {
        return array_values($this->locations[$projectId] ?? []);
    }
    public function find(int $projectId, string $publicId): ?LocalLocation
    {
        return $this->locations[$projectId][$publicId] ?? null;
    }
    public function save(LocalLocation $location): void
    {
        $this->locations[$location->projectId][$location->publicId] = $location;
    }
    public function remove(int $projectId, string $publicId): void
    {
        unset($this->locations[$projectId][$publicId], $this->checks[$projectId][$publicId], $this->reviews[$projectId][$publicId], $this->grids[$projectId][$publicId]);
    }
    public function ranking(int $projectId, string $publicId, string $date): array
    {
        return $this->checks[$projectId][$publicId][$date] ?? [];
    }
    public function saveRanking(int $projectId, string $publicId, string $date, array $result): void
    {
        $this->checks[$projectId][$publicId][$date] = $result;
    }
    public function history(int $projectId, string $publicId): array
    {
        $checks = $this->checks[$projectId][$publicId] ?? [];
        krsort($checks);
        $rows = [];
        foreach (array_slice($checks, 0, 12, true) as $date => $result) {
            $rows[] = ['date' => $date, 'result' => $result];
        } return $rows;
    }
    public function markChecked(int $projectId, string $publicId, string $date): void
    {
        $l = $this->find($projectId, $publicId);
        if ($l !== null) {
            $this->save(new LocalLocation($l->publicId, $l->projectId, $l->name, $l->cid, $l->area, $l->language, $l->keywords, $l->weekly, $date));
        }
    }
    public function due(string $before): array
    {
        return array_values(array_filter($this->dueRows, function (array $r) use ($before): bool {
            $location = $this->find($r['project_id'], $r['location']);
            return $location !== null && $location->weekly && ($location->checkedOn === null || $location->checkedOn <= $before);
        }));
    }
    public function reviews(int $projectId, string $location): array
    {
        return $this->reviews[$projectId][$location] ?? [];
    }
    public function saveReviews(int $projectId, string $location, array $state): void
    {
        $this->reviews[$projectId][$location] = $state;
    }
    public function dispatch(string $mode, int $projectId, int $workspaceId, string $projectPublicId, string $location, int $delay = 0, ?string $gridId = null): void
    {
        $this->jobs[] = ['mode' => $mode, 'project' => $projectId, 'workspace' => $workspaceId, 'public' => $projectPublicId, 'location' => $location, 'delay' => $delay];
    }
}
