<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local;

interface LocationRepository
{
    /** @return list<LocalLocation> */
    public function locations(int $projectId): array;
    public function find(int $projectId, string $publicId): ?LocalLocation;
    public function save(LocalLocation $location): void;
    public function remove(int $projectId, string $publicId): void;
    /** @return array<string, mixed> */
    public function ranking(int $projectId, string $publicId, string $date): array;
    /** @param array<string, mixed> $result */
    public function saveRanking(int $projectId, string $publicId, string $date, array $result): void;
    /** @return list<array{date: string, result: array<string, mixed>}> */
    public function history(int $projectId, string $publicId): array;
    public function markChecked(int $projectId, string $publicId, string $date): void;
    /** @return list<array{project_id: int, workspace_id: int, public_id: string, location: string}> */
    public function due(string $before): array;
    /** @return array<string, mixed> */
    public function reviews(int $projectId, string $location): array;
    /** @param array<string, mixed> $state */
    public function saveReviews(int $projectId, string $location, array $state): void;
    /** @return list<BranchGrid> */
    public function grids(int $projectId, string $location): array;
    public function queuedGrid(int $projectId, string $location): ?BranchGrid;
    public function createGrid(int $projectId, string $location, BranchGrid $grid): void;
    public function saveGridPoint(int $projectId, string $publicId, int $index, ?int $position): void;
    public function finishGrid(int $projectId, string $publicId, string $status, ?string $error = null): void;
}
