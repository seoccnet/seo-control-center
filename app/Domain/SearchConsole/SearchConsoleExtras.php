<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Search Console data besides the web search import, stored per project:
 * preliminary (not yet final) daily totals, daily totals of the other Google
 * surfaces and the property's sitemaps. Written by the importer when it
 * refreshes the totals; read by the dashboards.
 */
interface SearchConsoleExtras
{
    /**
     * Replaces the preliminary days of the project.
     *
     * @param list<FreshDay> $days
     */
    public function replaceFreshDays(int $projectId, array $days): void;

    /**
     * Preliminary days newer than the newest final day, oldest first.
     *
     * @return list<FreshDay>
     */
    public function freshDays(int $projectId): array;

    /**
     * Inserts or replaces daily totals of one surface.
     *
     * @param list<DailyMetrics> $days
     */
    public function upsertSearchTypeDays(int $projectId, SearchType $type, array $days): void;

    /**
     * Totals per surface for the range; surfaces without rows are absent.
     *
     * @return array<value-of<SearchType>, Metrics>
     */
    public function searchTypeTotals(int $projectId, DateRange $range): array;

    /** Newest day with any surface row, or null. */
    public function searchTypeLastDate(int $projectId): ?string;

    /**
     * Daily totals of one surface for the range, oldest first.
     *
     * @return list<DailyMetrics>
     */
    public function searchTypeDaily(int $projectId, SearchType $type, DateRange $range): array;

    /** @param list<Sitemap> $sitemaps */
    public function replaceSitemaps(int $projectId, array $sitemaps): void;

    /** When the sitemaps were last read from Google (UTC), or null. */
    public function sitemapsRefreshedAt(int $projectId): ?string;

    /**
     * Sitemaps with errors or warnings first, then by URL.
     *
     * @return list<Sitemap>
     */
    public function sitemaps(int $projectId): array;
}
