<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\FilteredRow;
use SeoControlCenter\Domain\SearchConsole\ImportScheduler;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\RowFilter;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\SearchConsole\SyncState;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * In-memory performance store/reader plus a recording import scheduler,
 * mirroring the PDO implementations' semantics.
 */
final class InMemorySearchPerformance implements SearchPerformanceStore, SearchPerformanceReader, ImportScheduler
{
    /** @var array<int, SyncState> */
    public array $states = [];

    /** @var array<int, array<string, Metrics>> project => date => totals */
    public array $daily = [];

    /** @var array<int, array<string, array<string, list<DimensionRow>>>> project => date => dimension => rows */
    public array $details = [];

    /** @var list<array{projectId: int, workspaceId: int}> */
    public array $importRequests = [];

    public int $purges = 0;

    public function coveredDates(int $projectId, DateRange $range): array
    {
        $dates = array_fill_keys(array_map('strval', array_keys($this->details[$projectId] ?? [])), true);
        foreach ($this->daily[$projectId] ?? [] as $date => $metrics) {
            if ($metrics->impressions === 0) {
                $dates[(string) $date] = true;
            }
        }
        $list = array_values(array_filter(array_keys($dates), static fn(int|string $d): bool => $range->contains((string) $d)));
        $list = array_map('strval', $list);
        sort($list);
        return $list;
    }

    public function coveredDays(int $projectId, DateRange $range): int
    {
        $dates = array_fill_keys(array_keys($this->details[$projectId] ?? []), true);
        foreach ($this->daily[$projectId] ?? [] as $date => $metrics) {
            if ($metrics->impressions === 0) {
                $dates[$date] = true;
            }
        }
        return count(array_filter(array_keys($dates), $range->contains(...)));
    }

    public function compared(int $projectId, PerformanceDimension $dimension, DateRange $range, int $limit): array
    {
        $previous = array_column($this->top($projectId, $dimension, $range->previous(), PHP_INT_MAX), null, 'key');
        $rows = [];
        foreach ($this->top($projectId, $dimension, $range, PHP_INT_MAX) as $row) {
            if (isset($previous[$row->key])) {
                $rows[] = ['key' => $row->key, 'current' => $row->metrics, 'previous' => $previous[$row->key]->metrics];
            }
        }
        usort($rows, static fn(array $a, array $b): int => [abs($b['current']->clicks - $b['previous']->clicks), $b['current']->impressions, $a['key']] <=> [abs($a['current']->clicks - $a['previous']->clicks), $a['current']->impressions, $b['key']]);
        // Balance the bounded sample across gains, losses and unchanged rows.
        $counts = [-1 => 0, 0 => 0, 1 => 0];
        $ranks = [];
        foreach ($rows as $row) {
            $direction = $row['current']->clicks <=> $row['previous']->clicks;
            $ranks[$row['key']] = ++$counts[$direction];
        }
        usort($rows, static fn(array $a, array $b): int => [$ranks[$a['key']], -abs($a['current']->clicks - $a['previous']->clicks), -$a['current']->impressions, $a['key']] <=> [$ranks[$b['key']], -abs($b['current']->clicks - $b['previous']->clicks), -$b['current']->impressions, $b['key']]);
        return ['total' => count($rows), 'rows' => array_slice($rows, 0, max(1, min(500, $limit)))];
    }

    /** @var array<int, array<string, list<\SeoControlCenter\Domain\SearchConsole\QueryPageRow>>> */
    public array $queryPages = [];

    public function replaceQueryPages(int $projectId, string $date, array $rows): void
    {
        $this->queryPages[$projectId][$date] = $rows;
    }

    public function pendingQueryPageDays(int $projectId, int $limit): array
    {
        $span = $this->dataSpan($projectId);
        if ($span === null) {
            return [];
        }
        $range = DateRange::endingOn($span['last'], 28);
        $dates = array_values(array_filter(array_map('strval', array_keys($this->details[$projectId] ?? [])), fn(string $date): bool => $range->contains($date) && !isset($this->queryPages[$projectId][$date])));
        rsort($dates);
        return array_slice($dates, 0, $limit);
    }

    public function requestImport(int $projectId, int $workspaceId): void
    {
        $this->importRequests[] = ['projectId' => $projectId, 'workspaceId' => $workspaceId];
    }

    public function syncState(int $projectId): ?SyncState
    {
        return $this->states[$projectId] ?? null;
    }

    public function saveSyncState(SyncState $state): void
    {
        $this->states[$state->projectId] = $state;
    }

    public function purge(int $projectId): void
    {
        ++$this->purges;
        unset($this->states[$projectId], $this->daily[$projectId], $this->details[$projectId], $this->queryPages[$projectId]);
    }

    public function upsertDailyTotals(int $projectId, array $days): void
    {
        foreach ($days as $day) {
            $this->daily[$projectId][$day->date] = $day->metrics;
        }
    }

    public function pendingDays(int $projectId, int $limit): array
    {
        $pending = [];
        foreach ($this->daily[$projectId] ?? [] as $date => $m) {
            if ($m->impressions > 0 && !isset($this->details[$projectId][$date])) {
                $pending[] = (string) $date;
            }
        }
        rsort($pending);

        return array_slice($pending, 0, $limit);
    }

    public function countPendingDays(int $projectId): int
    {
        return count($this->pendingDays($projectId, PHP_INT_MAX));
    }

    public function refreshRollups(int $projectId, \Closure $continue): int
    {
        return 0; // The in-memory reader always aggregates days.
    }

    public function replaceDay(int $projectId, string $date, array $rows): void
    {
        $this->details[$projectId][$date] = $rows;
    }

    public function dataSpan(int $projectId): ?array
    {
        $dates = array_map('strval', array_keys($this->daily[$projectId] ?? []));
        if ($dates === []) {
            return null;
        }
        sort($dates);

        return ['first' => $dates[0], 'last' => $dates[count($dates) - 1]];
    }

    public function totals(int $projectId, DateRange $range): Metrics
    {
        return Metrics::sum(array_map(static fn(DailyMetrics $d): Metrics => $d->metrics, $this->daily($projectId, $range)));
    }

    public function daily(int $projectId, DateRange $range): array
    {
        $result = [];
        foreach ($this->daily[$projectId] ?? [] as $date => $m) {
            if ($range->contains((string) $date)) {
                $result[] = new DailyMetrics((string) $date, $m);
            }
        }
        usort($result, static fn(DailyMetrics $a, DailyMetrics $b): int => strcmp($a->date, $b->date));

        return $result;
    }

    public function top(int $projectId, PerformanceDimension $dimension, DateRange $range, int $limit): array
    {
        $grouped = [];
        foreach ($this->details[$projectId] ?? [] as $date => $dims) {
            if (!$range->contains((string) $date)) {
                continue;
            }
            foreach ($dims[$dimension->value] ?? [] as $row) {
                $grouped[$row->key][] = $row->metrics;
            }
        }
        $rows = [];
        foreach ($grouped as $key => $metrics) {
            $rows[] = new DimensionRow((string) $key, Metrics::sum($metrics));
        }
        usort($rows, static fn(DimensionRow $a, DimensionRow $b): int => [$b->metrics->clicks, $b->metrics->impressions] <=> [$a->metrics->clicks, $a->metrics->impressions]);

        return array_slice($rows, 0, $limit);
    }

    public function matching(int $projectId, PerformanceDimension $dimension, DateRange $range, RowFilter $filter, int $limit, int $offset = 0): array
    {
        if ($dimension !== PerformanceDimension::Query && $dimension !== PerformanceDimension::Page) {
            throw new \InvalidArgumentException('Filter queries or pages.');
        }
        $current = array_column(array_map(static fn(DimensionRow $r): array => ['key' => $r->key, 'm' => $r->metrics], $this->top($projectId, $dimension, $range, PHP_INT_MAX)), 'm', 'key');
        $previous = $filter->compare ? array_column(array_map(static fn(DimensionRow $r): array => ['key' => $r->key, 'm' => $r->metrics], $this->top($projectId, $dimension, $range->previous(), PHP_INT_MAX)), 'm', 'key') : [];
        $rows = [];
        foreach (array_unique([...array_keys($current), ...array_keys($previous)]) as $key) {
            $row = new FilteredRow((string) $key, $current[$key] ?? Metrics::zero(), $filter->compare ? ($previous[$key] ?? Metrics::zero()) : null);
            if ($filter->matches($row)) {
                $rows[] = $row;
            }
        }
        usort($rows, $filter->compare(...));

        return ['total' => count($rows), 'totals' => Metrics::sum(array_map(static fn(FilteredRow $r): Metrics => $r->current, $rows)), 'rows' => array_slice($rows, max(0, $offset), max(1, min(500, $limit)))];
    }

    public function queryTotals(int $projectId, string $query, DateRange $range): ?Metrics
    {
        $metrics = [];
        foreach ($this->details[$projectId] ?? [] as $date => $dims) {
            foreach ($range->contains((string) $date) ? ($dims[PerformanceDimension::Query->value] ?? []) : [] as $row) {
                if ($row->key === $query) {
                    $metrics[] = $row->metrics;
                }
            }
        }

        return $metrics === [] ? null : Metrics::sum($metrics);
    }
}
