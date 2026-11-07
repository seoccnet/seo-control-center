<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Overview;

use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\DimensionRows;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Bing\BingService;
use SeoControlCenter\Modules\Competitors\CompetitorService;
use SeoControlCenter\Modules\Opportunities\OpportunityService;
use SeoControlCenter\Modules\Rankings\RankingService;
use SeoControlCenter\Modules\SearchConsole\SearchConsoleService;

/**
 * The project overview: one screen with the headline numbers of every
 * module, read through their services (no direct access to their storage).
 */
final readonly class ProjectOverview
{
    /** Range keys offered on the overview => days (same set as the Performance page). */
    public const array RANGES = ['7d' => 7, '28d' => 28, '3m' => 91, '6m' => 182, '12m' => 365, '16m' => 486];
    public const string DEFAULT_RANGE = '28d';

    private const int TOP_ROWS = 5;
    private const int OPPORTUNITIES = 5;
    private const int COMPETITORS = 4;
    /** Bing keeps about six months; longer overview ranges show its six months. */
    private const int BING_MAX_DAYS = 182;
    /** Rows per engine read before combining the top pages. */
    private const int MERGE_ROWS = 50;

    public function __construct(
        private ProjectService $projects,
        private SearchConsoleService $searchConsole,
        private SearchPerformanceReader $reader,
        private SearchPerformanceStore $store,
        private RankingService $rankings,
        private CompetitorService $competitors,
        private OpportunityService $opportunities,
        private BingService $bing,
        private OverviewEvidence $evidence,
        private Clock $clock,
        private \SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras $extras,
    ) {}

    /**
     * The few numbers a dashboard card shows: last 28 days of Search Console
     * (with the previous 28 for the change), a daily click series and the
     * number of open opportunities. Bing's totals for the same days come
     * along when a Bing site is linked; the click series adds them.
     *
     * @return array{search: array{totals: Metrics, previous: Metrics, hasPrevious: bool, clicks: list<float>, range: DateRange}|null,
     *     bing: array{range: DateRange, totals: Metrics, previous: Metrics, hasPrevious: bool, staleSince: ?string}|null, openOpportunities: int}
     */
    public function summary(User $user, Project $project): array
    {
        $span = $this->reader->dataSpan($project->id);
        $search = null;
        $bing = null;
        if ($span !== null) {
            $range = DateRange::endingOn($span['last'], self::RANGES[self::DEFAULT_RANGE]);
            $bing = $this->bingFor($project, $range);
            $byDate = $this->clicksByDate($project, $range, $bing !== null);
            $search = [
                'totals' => $this->reader->totals($project->id, $range),
                'previous' => $this->reader->totals($project->id, $range->previous()),
                'hasPrevious' => $span['first'] <= $range->previous()->toString(),
                'clicks' => array_map(static fn(string $d): float => $byDate[$d] ?? 0.0, $range->eachDay()),
                'range' => $range,
            ];
        } elseif ($this->bing->hasData($project->id)) {
            $bing = $this->bing->window($project, self::RANGES[self::DEFAULT_RANGE]);
        }

        return [
            'search' => $search,
            'bing' => $bing,
            'openOpportunities' => $this->opportunities->overview($user, $project, 'open', null, 1)['statusCounts']['open'] ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, Project $project, string $rangeKey): array
    {
        $rangeKey = isset(self::RANGES[$rangeKey]) ? $rangeKey : self::DEFAULT_RANGE;
        $span = $this->reader->dataSpan($project->id);
        $search = null;
        $bingOnly = null;
        if ($span !== null) {
            // Google and Bing are combined over the same days: the Search Console range.
            $range = DateRange::endingOn($span['last'], self::RANGES[$rangeKey]);
            $bing = $this->bingFor($project, $range);
            $daily = $this->dailyMetrics($project, $range, $bing !== null);
            $byDate = array_column($daily, 'clicks', 'date');
            $totals = $this->reader->totals($project->id, $range);
            $previous = $this->reader->totals($project->id, $range->previous());
            $pages = $this->reader->top($project->id, PerformanceDimension::Page, $range, self::MERGE_ROWS);
            if ($bing !== null) {
                $pages = DimensionRows::merge($pages, $this->bing->top($project->id, PerformanceDimension::Page, $range, self::MERGE_ROWS));
            }
            $search = [
                'range' => $range,
                'totals' => $totals,
                'previous' => $previous,
                'hasPrevious' => count($this->reader->daily($project->id, $range)) === $range->days() && count($this->reader->daily($project->id, $range->previous())) === $range->days(),
                'bing' => $bing,
                'daily' => $daily,
                'forecast' => [
                    'asOf' => $this->clock->now()->format('Y-m-d'),
                    'history' => $this->dailyMetrics($project, DateRange::endingOn($span['last'], 180), false),
                    'display' => $bing === null ? $daily : $this->dailyMetrics($project, $range, false),
                    // Google's not-yet-final days: shown against the projection, never used to fit it.
                    'preliminary' => array_map(static fn(\SeoControlCenter\Domain\SearchConsole\FreshDay $d): array => ['date' => $d->date, 'clicks' => $d->metrics->clicks, 'impressions' => $d->metrics->impressions, 'ctr' => $d->metrics->ctr() * 100, 'position' => $d->metrics->impressions > 0 ? $d->metrics->position : null, 'complete' => $d->complete], $this->extras->freshDays($project->id)),
                ],
                'combined' => $bing !== null ? self::combine($totals, $bing['totals']) : $totals,
                'combinedPrevious' => $bing !== null ? self::combine($previous, $bing['previous']) : $previous,
                'clicks' => array_map(static fn(string $d): array => [$d, $byDate[$d] ?? 0.0], $range->eachDay()),
                'pages' => array_slice($pages, 0, self::TOP_ROWS),
            ];
        } elseif ($this->bing->hasData($project->id)) {
            $bingOnly = $this->bing->window($project, min(self::RANGES[$rangeKey], self::BING_MAX_DAYS));
            if ($bingOnly !== null) {
                $window = $bingOnly['range'];
                $bingOnly['hasPrevious'] = count($this->bing->daily($project->id, $window)) === $window->days() && count($this->bing->daily($project->id, $window->previous())) === $window->days();
            }
        }
        $rankings = $this->rankings->overview($user, $project, 1);
        $competitors = $this->competitors->overview($user, $project);
        $opportunities = $this->opportunities->overview($user, $project, 'open', null, 1);
        $link = $this->searchConsole->projectLink($project);

        $evidenceRange = $search['range'] ?? $bingOnly['range'] ?? $this->evidence->fallbackRange($project, self::RANGES[$rangeKey]);
        return [
            'evidence' => $this->evidence->build($project, $evidenceRange),
            'rangeKey' => $rangeKey,
            'ranges' => self::RANGES,
            'link' => $link,
            'sync' => $this->store->syncState($project->id),
            'search' => $search,
            'bing' => $bingOnly,
            'rankings' => [
                'enabled' => $rankings['settings']->enabled,
                'keywordCount' => $rankings['keywordCount'],
                'latest' => $rankings['latest'],
                'compare' => $rankings['compare'],
                'keywords' => array_slice($rankings['keywords'], 0, self::TOP_ROWS),
            ],
            'competitors' => array_slice($competitors['rows'], 0, self::COMPETITORS),
            'competitorCount' => count($competitors['rows']),
            'opportunities' => array_slice($opportunities['opportunities'], 0, self::OPPORTUNITIES),
            'lastAnalyzedAt' => $opportunities['lastAnalyzedAt'],
            'openOpportunities' => $opportunities['statusCounts']['open'] ?? 0,
            'setup' => [
                'searchConsole' => $link !== null,
                'keywords' => $rankings['keywordCount'] > 0,
                'rankTracking' => $rankings['settings']->enabled,
                'competitors' => $competitors['rows'] !== [],
            ],
            'canImport' => $this->projects->can($user, Permission::IntegrationsManage, $project),
        ];
    }

    /**
     * Bing's totals for the given (Search Console) range, or null without Bing data.
     *
     * @return array{range: DateRange, totals: Metrics, previous: Metrics, hasPrevious: bool, staleSince: ?string}|null
     */
    private function bingFor(Project $project, DateRange $range): ?array
    {
        $span = $this->bing->span($project->id);
        if ($span === null) {
            return null;
        }

        return [
            'range' => $range,
            'totals' => $this->bing->totals($project->id, $range),
            'previous' => $this->bing->totals($project->id, $range->previous()),
            'hasPrevious' => count($this->bing->daily($project->id, $range)) === $range->days() && count($this->bing->daily($project->id, $range->previous())) === $range->days(),
            'staleSince' => null,
        ];
    }

    /**
     * Clicks per day, Google plus Bing when $withBing.
     *
     * @return array<string, float>
     */
    private function clicksByDate(Project $project, DateRange $range, bool $withBing): array
    {
        $byDate = [];
        foreach ($this->reader->daily($project->id, $range) as $day) {
            $byDate[$day->date] = (float) $day->metrics->clicks;
        }
        if ($withBing) {
            foreach ($this->bing->daily($project->id, $range) as $day) {
                $byDate[$day->date] = ($byDate[$day->date] ?? 0.0) + $day->metrics->clicks;
            }
        }

        return $byDate;
    }

    /** @return list<array{date: string, clicks: int, impressions: int, ctr: float, position: ?float}> */
    private function dailyMetrics(Project $project, DateRange $range, bool $withBing): array
    {
        $days = [];
        foreach ($this->reader->daily($project->id, $range) as $day) {
            $days[$day->date] = ['date' => $day->date, 'clicks' => $day->metrics->clicks, 'impressions' => $day->metrics->impressions, 'ctr' => $day->metrics->ctr() * 100, 'position' => $day->metrics->impressions > 0 ? $day->metrics->position : null];
        }
        if ($withBing) {
            foreach ($this->bing->daily($project->id, $range) as $day) {
                $row = $days[$day->date] ?? ['date' => $day->date, 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => null];
                $row['clicks'] += $day->metrics->clicks;
                $row['impressions'] += $day->metrics->impressions;
                $row['ctr'] = $row['impressions'] > 0 ? $row['clicks'] / $row['impressions'] * 100 : 0.0;
                $days[$day->date] = $row;
            }
        }
        ksort($days);

        return array_values($days);
    }

    /**
     * Google plus Bing clicks and impressions; the position stays Google's (Bing has no daily position).
     */
    private static function combine(Metrics $google, Metrics $bing): Metrics
    {
        return new Metrics($google->clicks + $bing->clicks, $google->impressions + $bing->impressions, $google->position);
    }
}
