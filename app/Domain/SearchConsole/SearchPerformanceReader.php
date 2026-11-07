<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Read side for dashboards, reports and (later) MCP tools. All reads are
 * per project; authorization happens before a project id reaches this.
 */
interface SearchPerformanceReader
{
    /** Completed dimension imports plus recorded zero-impression dates (no breakdown is needed). */
    public function coveredDays(int $projectId, DateRange $range): int;

    /**
     * The dates coveredDays() counts, ascending. A covered date with no
     * clicks or impressions is a measured zero; a date not listed was never
     * imported completely.
     *
     * @return list<string>
     */
    public function coveredDates(int $projectId, DateRange $range): array;

    /** Rows observed in both periods, balanced across gains, losses and unchanged rows.
     * @return array{total: int, rows: list<array{key: string, current: Metrics, previous: Metrics}>}
     */
    public function compared(int $projectId, PerformanceDimension $dimension, DateRange $range, int $limit): array;

    /**
     * @return array{first: string, last: string}|null imported date span
     */
    public function dataSpan(int $projectId): ?array;

    public function totals(int $projectId, DateRange $range): Metrics;

    /**
     * @return list<DailyMetrics> days with data, oldest first
     */
    public function daily(int $projectId, DateRange $range): array;

    /**
     * Top rows of a breakdown in the range, by clicks then impressions.
     *
     * @return list<DimensionRow>
     */
    public function top(int $projectId, PerformanceDimension $dimension, DateRange $range, int $limit): array;

    /**
     * Queries or pages matching the filter over every stored row in the range
     * (not only the top rows), sorted as the filter says. With a comparison,
     * rows seen only in the previous period are included with zero current
     * metrics unless a metric bound excludes them. `totals` sums the current
     * period of every matching row, not only the returned page.
     *
     * @return array{total: int, totals: Metrics, rows: list<FilteredRow>}
     */
    public function matching(int $projectId, PerformanceDimension $dimension, DateRange $range, RowFilter $filter, int $limit, int $offset = 0): array;

    /**
     * Totals of one exact search query in the range, or null when the query has no data.
     */
    public function queryTotals(int $projectId, string $query, DateRange $range): ?Metrics;
}
