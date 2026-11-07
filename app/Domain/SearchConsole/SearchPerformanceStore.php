<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * Write side of imported search performance data. Every method is scoped
 * to one project; callers are the importer and project lifecycle code.
 */
interface SearchPerformanceStore
{
    public function syncState(int $projectId): ?SyncState;

    public function saveSyncState(SyncState $state): void;

    /**
     * Deletes all imported performance data and progress of the project.
     */
    public function purge(int $projectId): void;

    /**
     * Inserts or replaces daily property totals.
     *
     * @param list<DailyMetrics> $days
     */
    public function upsertDailyTotals(int $projectId, array $days): void;

    /**
     * Days that have totals with impressions but no imported detail yet, newest first.
     *
     * @return list<string>
     */
    public function pendingDays(int $projectId, int $limit): array;

    public function countPendingDays(int $projectId): int;

    /**
     * Replaces the detail rows of one day atomically and marks the day imported.
     *
     * @param array<value-of<PerformanceDimension>, list<DimensionRow>> $rows
     */
    public function replaceDay(int $projectId, string $date, array $rows): void;

    /** @param list<QueryPageRow> $rows */
    public function replaceQueryPages(int $projectId, string $date, array $rows): void;

    /** @return list<string> Recent imported days without joint data, newest first. */
    public function pendingQueryPageDays(int $projectId, int $limit): array;

    /**
     * Rebuilds the monthly rollups of months whose days are all imported and
     * whose rollup is missing or outdated, newest first, while $continue()
     * allows. Returns the number of months rebuilt.
     *
     * @param \Closure(): bool $continue
     */
    public function refreshRollups(int $projectId, \Closure $continue): int;
}
