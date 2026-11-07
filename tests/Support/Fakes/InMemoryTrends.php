<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Opportunities\TrendReader;
use SeoControlCenter\Domain\Opportunities\TrendSnapshot;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\Shared\DateRange;

final class InMemoryTrends implements TrendReader
{
    /** @var array<int, TrendSnapshot> */
    public array $snapshots = [];

    public function __construct(private readonly InMemorySearchPerformance $search) {}
    public function snapshot(int $projectId, DateRange $range): TrendSnapshot
    {
        if (isset($this->snapshots[$projectId])) {
            return $this->snapshots[$projectId];
        }
        $queries = $this->search->top($projectId, PerformanceDimension::Query, $range, PHP_INT_MAX);
        $pages = $this->search->top($projectId, PerformanceDimension::Page, $range, PHP_INT_MAX);
        $sort = static fn(DimensionRow $a, DimensionRow $b): int => [$b->metrics->impressions, $b->metrics->clicks, $a->key] <=> [$a->metrics->impressions, $a->metrics->clicks, $b->key];
        usort($queries, $sort);
        usort($pages, $sort);
        $previous = $older = $previousQueries = [];
        foreach ($this->search->top($projectId, PerformanceDimension::Query, $range->previous(), PHP_INT_MAX) as $row) {
            $previousQueries[$row->key] = $row->metrics;
        }
        foreach ($this->search->top($projectId, PerformanceDimension::Page, $range->previous(), PHP_INT_MAX) as $row) {
            $previous[$row->key] = $row->metrics;
        }
        foreach ($this->search->top($projectId, PerformanceDimension::Page, $range->previous()->previous(), PHP_INT_MAX) as $row) {
            $older[$row->key] = $row->metrics;
        }
        return new TrendSnapshot(array_slice($queries, 0, 500), array_slice($pages, 0, 500), $previous, $older, [], count($queries), count($pages), null, $previousQueries);
    }
}
