<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing;

use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Project links to Bing sites and the imported Bing data, always scoped by
 * project. Daily rows have no position (Bing does not report one per day).
 */
interface BingDataRepository
{
    public function link(int $projectId): ?BingSiteLink;

    /**
     * Links (or relinks) a project; a different site discards the old site's data.
     */
    public function saveLink(int $projectId, int $workspaceId, string $siteUrl, int $userId): void;

    /**
     * Removes the link; imported data is kept.
     */
    public function unlink(int $projectId): void;

    /**
     * @return list<array{projectId: int, workspaceId: int}>
     */
    public function linkedProjects(): array;

    public function setStatus(int $projectId, string $status, ?string $error = null): void;

    /**
     * Replaces the project's data with a full import (Bing always returns its whole history).
     *
     * @param list<DailyMetrics> $days
     * @param list<BingWeeklyRow> $rows
     */
    public function replaceData(int $projectId, array $days, array $rows): void;

    /**
     * @return array{first: string, last: string}|null newest and oldest day with daily data
     */
    public function dataSpan(int $projectId): ?array;

    /**
     * @return list<DailyMetrics>
     */
    public function daily(int $projectId, DateRange $range): array;

    public function totals(int $projectId, DateRange $range): Metrics;

    /**
     * Top rows of the weekly buckets ending inside the range, by clicks then impressions.
     *
     * @return list<DimensionRow>
     */
    public function top(int $projectId, PerformanceDimension $dimension, DateRange $range, int $limit): array;

    /**
     * Number of weekly buckets ending inside the range.
     */
    public function weeks(int $projectId, DateRange $range): int;

    /**
     * Replaces the crawl statistics and inbound link counts of the project.
     *
     * @param list<BingCrawlDay> $days
     * @param list<BingLinkCount> $links
     */
    public function replaceCrawlData(int $projectId, array $days, array $links): void;

    /**
     * @return list<BingCrawlDay> days inside the range, oldest first
     */
    public function crawlStats(int $projectId, DateRange $range): array;

    /**
     * Most linked pages first.
     *
     * @return list<BingLinkCount>
     */
    public function linkCounts(int $projectId, int $limit): array;

    /**
     * @return array{pages: int, links: int} pages with inbound links and their links in total
     */
    public function linkTotals(int $projectId): array;
}
