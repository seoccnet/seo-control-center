<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole;

use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\RowFilter;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * New queries (1.6.0): Search Console queries with impressions in the
 * latest period and none in the period before it, then checked against
 * the year before that, so a query back after a break is told apart from
 * one never seen. Early signal of new demand; stored data only.
 */
final readonly class NewQueriesService
{
    public const array PERIODS = [7, 28, 90];
    public const int LIMIT = 200;
    private const int MIN_IMPRESSIONS = 2;
    private const string LOOKBACK = '-12 months';

    public function __construct(
        private SearchPerformanceReader $reader,
        private KeywordRepository $keywords,
        private ProjectService $projects,
    ) {}

    /**
     * @return array{
     *     days: int, range: ?DateRange, previous: ?DateRange, lookback: ?DateRange, complete: bool, total: int,
     *     rows: list<array{query: string, metrics: Metrics, firstTime: bool, tracked: bool}>,
     *     canTrack: bool, locale: KeywordLocale
     * }
     */
    public function report(User $user, Project $project, int $days): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 28;
        $locale = $this->keywords->lastLocale($project->id) ?? KeywordLocale::default();
        $empty = ['days' => $days, 'range' => null, 'previous' => null, 'lookback' => null, 'complete' => false, 'total' => 0, 'rows' => [], 'canTrack' => false, 'locale' => $locale];
        $span = $this->reader->dataSpan($project->id);
        if ($span === null) {
            return $empty;
        }
        $range = DateRange::endingOn($span['last'], $days);
        $previous = DateRange::endingOn($range->from->modify('-1 day')->format('Y-m-d'), $days);
        $lookbackEnd = $previous->from->modify('-1 day');
        $lookbackFrom = max($span['first'], $lookbackEnd->modify(self::LOOKBACK)->format('Y-m-d'));
        $lookback = $lookbackFrom <= $lookbackEnd->format('Y-m-d') ? new DateRange($lookbackFrom, $lookbackEnd->format('Y-m-d')) : null;
        $hasLookback = $lookback !== null;
        $complete = $this->reader->coveredDays($project->id, $range) >= $days && $this->reader->coveredDays($project->id, $previous) >= $days;

        $found = $this->reader->matching($project->id, PerformanceDimension::Query, $range, new RowFilter(minImpressions: self::MIN_IMPRESSIONS, sort: 'impressions', presence: 'new'), self::LIMIT);
        $tracked = array_flip(array_map('mb_strtolower', $this->keywords->texts($project->id)));
        $rows = [];
        foreach ($found['rows'] as $row) {
            $earlier = $lookback !== null ? $this->reader->queryTotals($project->id, $row->key, $lookback) : null;
            $rows[] = [
                'query' => $row->key,
                'metrics' => $row->current,
                'firstTime' => $earlier === null || $earlier->impressions === 0,
                'tracked' => isset($tracked[mb_strtolower($row->key)]),
            ];
        }

        return [
            'days' => $days,
            'range' => $range,
            'previous' => $previous,
            'lookback' => $lookback,
            'complete' => $complete,
            'total' => $found['total'],
            'rows' => $rows,
            'canTrack' => $this->projects->can($user, Permission::ProjectsEdit, $project),
            'locale' => $locale,
        ];
    }
}
