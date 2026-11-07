<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local;

use SeoControlCenter\Domain\Local\BusinessProfile;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalGrid;
use SeoControlCenter\Domain\Local\LocalResult;

/**
 * Local SEO settings, keywords, Maps positions and profile snapshots,
 * always scoped by project.
 */
interface LocalRepository
{
    public function settings(int $projectId): ?LocalSettings;

    public function projectDomain(int $projectId): ?string;

    /**
     * Saves the business and area; a changed business name forgets the matched Google listing.
     */
    public function saveSettings(int $projectId, int $workspaceId, string $businessName, LocalArea $area, string $language, bool $weekly, int $userId): void;

    public function setStatus(int $projectId, string $status, ?string $error = null): void;

    public function setCid(int $projectId, string $cid): void;

    public function markChecked(int $projectId, string $date): void;

    /**
     * @return list<array{id: int, keyword: string}> alphabetically
     */
    public function keywords(int $projectId): array;

    /**
     * @param list<string> $keywords
     * @return int keywords added (existing ones are skipped)
     */
    public function addKeywords(int $projectId, array $keywords): int;

    public function removeKeyword(int $projectId, string $keyword): bool;

    /**
     * @return list<array{id: int, keyword: string}> keywords without a result on $date
     */
    public function unchecked(int $projectId, string $date): array;

    /**
     * @param list<LocalResult> $top
     */
    public function recordRanking(int $projectId, int $keywordId, string $date, ?int $position, array $top): void;

    public function recordProfile(int $projectId, string $date, BusinessProfile $profile): void;

    public function latestDate(int $projectId): ?string;

    public function dateBefore(int $projectId, string $date): ?string;

    /**
     * @return array<int, array{position: ?int, top: list<LocalResult>}> keyword id => result on $date
     */
    public function rankings(int $projectId, string $date): array;

    /**
     * @return list<array{date: string, profile: BusinessProfile}> newest first
     */
    public function profiles(int $projectId, int $limit): array;

    /**
     * Starts a ranking grid for a local keyword (status queued, no point checked).
     */
    public function createGrid(int $projectId, int $keywordId, string $date, int $size, int $spacing, LocalArea $center): void;

    /**
     * The project's queued grid, if any.
     *
     * @return array{id: int, keyword: string, grid: LocalGrid}|null
     */
    public function queuedGrid(int $projectId): ?array;

    public function saveGridPoint(int $gridId, int $index, ?int $position): void;

    public function finishGrid(int $gridId, string $status, ?string $error = null): void;

    /**
     * The newest grid of each local keyword.
     *
     * @return array<int, LocalGrid> keyword id => grid
     */
    public function latestGrids(int $projectId): array;

    /**
     * Projects with the weekly check on whose last check is on or before $before.
     *
     * @return list<array{projectId: int, workspaceId: int}>
     */
    public function dueWeekly(string $before): array;
}
