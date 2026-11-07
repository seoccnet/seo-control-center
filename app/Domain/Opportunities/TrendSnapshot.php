<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;

final readonly class TrendSnapshot
{
    /** @param list<DimensionRow> $queries
     * @param list<DimensionRow> $pages
     * @param array<string, Metrics> $previousPages
     * @param array<string, Metrics> $olderPages
     * @param array<string, float> $seasonalRatios prior-year completed-month ratio, not exact 28-day cohorts
     * @param array<string, Metrics> $previousQueries query => metrics of the preceding window (Google only)
     */
    public function __construct(public array $queries = [], public array $pages = [], public array $previousPages = [], public array $olderPages = [], public array $seasonalRatios = [], public int $queryCount = 0, public int $pageCount = 0, public ?string $projectName = null, public array $previousQueries = []) {}
}
