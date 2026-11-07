<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

use SeoControlCenter\Domain\Integrations\Connection;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Provider contract for first-party search performance data (INSTRUCTIONS.md §10).
 * The Google Search Console adapter implements it; domain code never sees Google's formats.
 */
interface SearchPerformanceProvider
{
    /**
     * @return list<SearchConsoleProperty>
     * @throws IntegrationException
     */
    public function listProperties(Connection $connection): array;

    /**
     * Property totals per day for the range. Days without data are absent;
     * only finalized data is returned.
     *
     * @return list<DailyMetrics>
     * @throws IntegrationException
     */
    public function dailyTotals(Connection $connection, string $siteUrl, DateRange $range): array;

    /**
     * Rows of one breakdown for a single day, highest traffic first, at most $maxRows.
     *
     * @return list<DimensionRow>
     * @throws IntegrationException
     */
    public function dayBreakdown(Connection $connection, string $siteUrl, string $date, PerformanceDimension $dimension, int $maxRows): array;

    /** @return list<QueryPageRow> Joint query/page rows, not separate marginal totals. */
    public function dayQueryPages(Connection $connection, string $siteUrl, string $date, int $maxRows): array;

    /**
     * Web search totals per day including data Google has not finalized yet.
     *
     * @return list<FreshDay> sorted by date
     * @throws IntegrationException
     */
    public function freshDailyTotals(Connection $connection, string $siteUrl, DateRange $range): array;

    /**
     * Finalized daily totals of another Google surface (Discover, News, image, video).
     *
     * @return list<DailyMetrics> sorted by date; position 0 where the surface has none
     * @throws IntegrationException
     */
    public function searchTypeDailyTotals(Connection $connection, string $siteUrl, SearchType $type, DateRange $range): array;

    /**
     * @return list<Sitemap>
     * @throws IntegrationException
     */
    public function sitemaps(Connection $connection, string $siteUrl): array;
}
