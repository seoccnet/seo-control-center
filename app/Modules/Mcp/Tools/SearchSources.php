<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\DimensionRows;
use SeoControlCenter\Domain\SearchConsole\FilteredRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\RowFilter;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Bing\BingService;

/**
 * Search data for the MCP tools from one source: "google" (Search Console),
 * "bing" (Bing Webmaster Tools) or "combined" (both over the Search Console
 * range; clicks and impressions add up, position stays Google's because Bing
 * reports no site-wide position per day).
 */
final readonly class SearchSources
{
    public const array SOURCES = ['combined', 'google', 'bing'];
    public const int CANDIDATES = 500;
    private const int BING_FILTER_ROWS = 5000;

    public function __construct(
        private SearchPerformanceReader $google,
        private BingService $bing,
    ) {}

    /**
     * @return array<string, mixed> JSON schema of the "source" argument
     */
    public static function schema(): array
    {
        return [
            'type' => 'string',
            'enum' => self::SOURCES,
            'default' => 'combined',
            'description' => 'combined = Google + Bing (default; position is Google\'s), google = Search Console only, bing = Bing Webmaster Tools only.',
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function source(array $arguments): string
    {
        $source = $arguments['source'] ?? 'combined';

        return is_string($source) && in_array($source, self::SOURCES, true) ? $source : 'combined';
    }

    /**
     * The range for the source, or null when it has no data. "combined" uses the
     * Search Console range, or Bing's when only Bing has data.
     *
     * @return array{range: DateRange, google: bool, bing: bool, hasPrevious: bool}|null
     */
    public function range(Project $project, string $source, int $days): ?array
    {
        $googleSpan = $source !== 'bing' ? $this->google->dataSpan($project->id) : null;
        $bingSpan = $source !== 'google' ? $this->bing->span($project->id) : null;
        if ($googleSpan !== null) {
            $range = DateRange::endingOn($googleSpan['last'], $days);
            $first = $googleSpan['first'];
        } elseif ($bingSpan !== null) {
            $range = $this->bing->window($project, $days)['range'] ?? DateRange::endingOn($bingSpan['last'], $days);
            $first = $bingSpan['first'];
        } else {
            return null;
        }

        return ['range' => $range, 'google' => $googleSpan !== null, 'bing' => $bingSpan !== null, 'hasPrevious' => $first <= $range->previous()->toString()];
    }

    /**
     * @param array{range: DateRange, google: bool, bing: bool, hasPrevious: bool} $scope
     */
    public function totals(Project $project, array $scope, DateRange $range): Metrics
    {
        $google = $scope['google'] ? $this->google->totals($project->id, $range) : null;
        $bing = $scope['bing'] ? $this->bing->totals($project->id, $range) : null;
        if ($google !== null && $bing !== null) {
            return new Metrics($google->clicks + $bing->clicks, $google->impressions + $bing->impressions, $google->position);
        }

        return $google ?? $bing ?? Metrics::zero();
    }

    /**
     * @param array{range: DateRange, google: bool, bing: bool, hasPrevious: bool} $scope
     * @return list<DimensionRow>
     */
    public function top(Project $project, array $scope, PerformanceDimension $dimension, int $limit): array
    {
        $rows = [];
        if ($scope['google']) {
            $rows[] = $this->google->top($project->id, $dimension, $scope['range'], $limit);
        }
        if ($scope['bing']) {
            $rows[] = $this->bing->top($project->id, $dimension, $scope['range'], $limit);
        }

        return array_slice(DimensionRows::merge(...$rows), 0, $limit);
    }

    /**
     * Which engines contributed, for the tool response.
     *
     * @param array{range: DateRange, google: bool, bing: bool, hasPrevious: bool} $scope
     * @return list<string>
     */
    public static function engines(array $scope): array
    {
        return array_values(array_filter([$scope['google'] ? 'google' : null, $scope['bing'] ? 'bing' : null]));
    }

    /** @param array{range: DateRange, google: bool, bing: bool, hasPrevious: bool} $scope
     * @param array<string, mixed> $arguments
     * @return array{range: DateRange, google: bool, bing: bool, hasPrevious: bool}
     */
    public function dated(Project $project, array $scope, array $arguments): array
    {
        $scope['range'] = ReadInput::range($arguments, $scope['range'], 486);
        $google = $scope['google'] ? $this->google->dataSpan($project->id) : null;
        $bing = $scope['bing'] ? $this->bing->span($project->id) : null;
        $scope['google'] = $google !== null && $scope['range']->fromString() <= $google['last'] && $scope['range']->toString() >= $google['first'];
        $scope['bing'] = $bing !== null && $scope['range']->fromString() <= $bing['last'] && $scope['range']->toString() >= $bing['first'];
        $first = $google['first'] ?? $bing['first'] ?? null;
        $scope['hasPrevious'] = $first !== null && $first <= $scope['range']->previous()->toString();
        return $scope;
    }

    /** @param array{range: DateRange, google: bool, bing: bool, hasPrevious: bool} $scope
     * @return array{rows: list<DimensionRow>, candidate_limit: int, truncated: bool}
     */
    public function candidates(Project $project, array $scope, PerformanceDimension $dimension): array
    {
        // The Search Console reader returns at most 500 top rows; ask for one more to detect truncation.
        $google = $scope['google'] ? $this->google->top($project->id, $dimension, $scope['range'], self::CANDIDATES + 1) : [];
        $bing = $scope['bing'] ? $this->bing->top($project->id, $dimension, $scope['range'], self::CANDIDATES + 1) : [];
        $truncated = count($google) >= self::CANDIDATES || count($bing) > self::CANDIDATES;
        return ['rows' => DimensionRows::merge(...[array_slice($google, 0, self::CANDIDATES), array_slice($bing, 0, self::CANDIDATES)]), 'candidate_limit' => self::CANDIDATES, 'truncated' => $truncated];
    }

    /**
     * Filtered rows over all stored Search Console rows (SQL), or over Bing's
     * top rows when Bing is the only source.
     *
     * @param array{range: DateRange, google: bool, bing: bool, hasPrevious: bool} $scope
     * @return array{engine: string, total: int, totals: Metrics, rows: list<FilteredRow>, exhaustive: bool}
     */
    public function filtered(Project $project, array $scope, string $source, PerformanceDimension $dimension, RowFilter $filter, int $limit, int $offset): array
    {
        if ($source !== 'bing' && $scope['google']) {
            return ['engine' => 'google', 'exhaustive' => true, ...$this->google->matching($project->id, $dimension, $scope['range'], $filter, $limit, $offset)];
        }
        if (!$scope['bing']) {
            return ['engine' => $source === 'bing' ? 'bing' : 'google', 'exhaustive' => true, 'total' => 0, 'totals' => Metrics::zero(), 'rows' => []];
        }
        $keyed = static function (array $rows): array {
            $result = [];
            foreach ($rows as $row) {
                $result[$row->key] = $row->metrics;
            }
            return $result;
        };
        $current = $keyed($this->bing->top($project->id, $dimension, $scope['range'], self::BING_FILTER_ROWS));
        $previous = $filter->compare ? $keyed($this->bing->top($project->id, $dimension, $scope['range']->previous(), self::BING_FILTER_ROWS)) : [];
        $rows = [];
        foreach (array_unique([...array_keys($current), ...array_keys($previous)]) as $key) {
            $row = new FilteredRow((string) $key, $current[$key] ?? Metrics::zero(), $filter->compare ? ($previous[$key] ?? Metrics::zero()) : null);
            if ($filter->matches($row)) {
                $rows[] = $row;
            }
        }
        usort($rows, $filter->compare(...));

        return ['engine' => 'bing', 'exhaustive' => count($current) < self::BING_FILTER_ROWS, 'total' => count($rows), 'totals' => Metrics::sum(array_map(static fn(FilteredRow $r): Metrics => $r->current, $rows)), 'rows' => array_slice($rows, $offset, $limit)];
    }

    /** @param array{range: DateRange, google: bool, bing: bool, hasPrevious: bool} $scope
     * @return array<string, mixed>
     */
    public function daily(Project $project, array $scope): array
    {
        $present = static fn(\SeoControlCenter\Domain\SearchConsole\DailyMetrics $d): array => ['date' => $d->date, ...Present::metrics($d->metrics)];
        return ['google' => array_map($present, $scope['google'] ? $this->google->daily($project->id, $scope['range']) : []), 'bing' => array_map($present, $scope['bing'] ? $this->bing->daily($project->id, $scope['range']) : []), 'note' => 'Separate engine series; absent dates are not proven zeros. Bing query/page rows use weekly buckets.'];
    }
}
