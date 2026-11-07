<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataQuality;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Opportunities\SignalCoverage;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Bing\BingService;
use SeoControlCenter\Modules\Opportunities\CoverageLabel;
use SeoControlCenter\Modules\Opportunities\SignalCollector;

/**
 * Data quality of one project, from stored data only: the freshness of every
 * dataset (the opportunity engine's coverage rules), gaps in the daily
 * imports, GA4 coverage and attribution limits, and combinations of sources
 * that are not directly comparable. Missing data is never reported as zero.
 * The project must already be authorized (ProjectService or ToolContext).
 */
final readonly class DataQualityService
{
    public const array GROUPS = ['search', 'analytics', 'rankings', 'serp', 'research', 'backlinks', 'technical', 'ai', 'local', 'context'];
    public const int WINDOW_DAYS = 90;
    /** Google reports final daily data about two to three days late. */
    private const int SEARCH_CONSOLE_LAG = 4;
    /** Bing data ends about three days before today; imports run daily. */
    private const int BING_LAG = 6;
    private const int GA4_LAG = 3;
    private const int MAX_RANGES = 20;

    /** Source => [label, group, note]. Unknown sources are listed by key. */
    private const array SOURCES = [
        'search_console' => ['Google Search Console daily data', 'search', 'Needs the latest stored day to be complete; Google reports with a delay of about two days.'],
        'search_console_previous' => ['Search Console previous 28 days', 'search', 'Needed for period comparisons.'],
        'search_console_surfaces' => ['Search Console image, video and news', 'search', null],
        'query_sample' => ['Query sample (Google and Bing)', 'search', 'Analyses read the top queries by impressions; partial means more queries exist than were read.'],
        'page_sample' => ['Page sample (Google and Bing)', 'search', 'Analyses read the top pages by impressions; partial means more pages exist than were read.'],
        'google_page_sample' => ['Google page sample', 'search', null],
        'query_pages' => ['Query-to-page pairs', 'search', 'Needed for cannibalization and landing-page checks.'],
        'sitemaps' => ['Sitemaps in Search Console', 'search', null],
        'page_dates' => ['Page update dates (sitemap)', 'search', 'Last-modified dates from your sitemap, read by the IndexNow sitemap check; needed for the refresh-aging-content check.'],
        'near_duplicates' => ['Near-duplicate pages (site audit text)', 'search', 'Pages of the latest site audit whose body text is nearly the same; needed for the merge-pages check.'],
        'ai_crawlers' => ['AI crawler access (robots.txt)', 'search', 'The weekly free check of which AI crawlers robots.txt lets in, shown on the AI visibility page; needed for the AI crawler access check.'],
        'bing' => ['Bing Webmaster Tools', 'search', 'Bing data ends about three days before today and keeps about six months.'],
        'ga4' => ['Google Analytics 4 landing pages', 'analytics', 'Missing also when the window is incomplete, limited or thresholded by Google.'],
        'rankings' => ['Rank tracking', 'rankings', 'Fresh within the tracking cadence plus two days.'],
        'ranking_comparison' => ['Rank comparison point', 'rankings', null],
        'seasonality' => ['Year-over-year monthly data', 'search', 'Needs 13 consecutive months of stored data.'],
        'serp_landscape' => ['Latest results pages', 'serp', 'Latest top 10 per tracked keyword; no history is stored.'],
        'ai_overviews' => ['AI Overview checks', 'serp', null],
        'serp_strength' => ['Competitor strength check', 'research', null],
        'research_ranked' => ['Ranked keywords research', 'research', null],
        'research_gap' => ['Keyword gap research', 'research', null],
        'research_ideas' => ['Keyword ideas', 'research', null],
        'research_suggestions' => ['Keyword suggestions', 'research', null],
        'backlink_profiles' => ['Backlink profiles', 'backlinks', null],
        'backlink_gap' => ['Backlink gap', 'backlinks', null],
        'backlink_lost' => ['Lost backlinks', 'backlinks', null],
        'site_audit' => ['Site audit crawl', 'technical', null],
        'rendered_audit' => ['Browser (rendered) audit', 'technical', null],
        'web_vitals' => ['Core Web Vitals field data (CrUX)', 'technical', 'Field data from real Chrome users; not a lab test.'],
        'ai_mentions' => ['AI mention metrics', 'ai', null],
        'ai_answers' => ['Sampled AI answers', 'ai', 'Samples of answers to your prompts, not mention tracking.'],
        'local_rankings' => ['Local rankings', 'local', null],
        'local_profile' => ['Business profile check', 'local', null],
        'local_reviews' => ['Imported Google reviews', 'local', null],
        'query_splits' => ['Query-to-page split sample', 'search', 'Joint query and page rows for cannibalization checks; withheld when the 5,000-row bound is reached.'],
        'bing_crawl' => ['Bing crawl and index counts', 'technical', 'Daily crawl statistics from Bing Webmaster Tools.'],
        'indexing' => ['Google URL Inspection results', 'technical', 'Inspections of saved and watched URLs.'],
        'indexing_traffic' => ['Clicks used to rank indexing issues', 'technical', 'Search Console clicks per page from the previous window; weighs which indexing problems matter most.'],
        'audit_traffic' => ['Search traffic for site audit pages', 'technical', 'Search Console and GA4 data joined to the latest site audit to weigh technical issues.'],
        'internal_links' => ['Internal link graph', 'technical', 'From the latest completed site audit; withheld above 2,000 pages or 5,000 links.'],
        'annotations' => ['Change timeline notes', 'context', 'Always readable; available does not mean a note exists in the window.'],
        'page_monitoring' => ['Page monitoring', 'context', 'Missing when no page is watched.'],
        'alerts' => ['Alerts', 'context', 'Always readable; available does not mean an alert fired.'],
        'outcomes' => ['Opportunity outcomes', 'context', 'Results recorded for finished opportunities; always readable.'],
        'competitor_analysis' => ['Why they outrank you analyses', 'research', 'Analyses of topics in the last 30 days; missing when no topic was analyzed.'],
    ];

    public const array RULES = [
        'missing' => 'No usable stored data. Never treat it as zero clicks, rankings or links; the measurement does not exist.',
        'stale' => 'Data exists but is older than its freshness window. Say how old it is; do not present it as current.',
        'partial' => 'Only a top sample was read. Totals and "not found" conclusions from the sample are not exhaustive.',
        'available' => 'Fresh, complete stored data. A zero here is a measured zero.',
        'compatibility' => 'Do not add Google and Bing positions, compare GA4 sessions with Search Console clicks as if they were the same unit, or compare windows of different lengths.',
    ];

    private const array GA4_LIMITS = [
        'Only sessions in the Organic Search default channel group are imported, from all search engines together.',
        'Sessions, key events and purchase revenue are session-scoped and credited to the landing page of the session, not to a search query.',
        'Key events count every event marked as a key event in GA4; SEOcc cannot see which events those are.',
        'Consent mode, ad blockers and missing tags lower GA4 numbers; Search Console clicks are usually higher than GA4 sessions.',
        'Large properties are imported up to 10,000 landing-page rows per window; beyond that GA4 marks the data as limited.',
    ];

    public function __construct(
        private SignalCollector $signals,
        private SearchPerformanceReader $searchConsole,
        private ProjectPropertyRepository $properties,
        private BingService $bing,
        private Ga4Repository $ga4,
        private Clock $clock,
        /** @var ?\Closure(Project): array<string, mixed> the business-event assessment (ADR-042) */
        private ?\Closure $businessMeasurement = null,
    ) {}

    /**
     * @return list<array{source: string, label: string, group: string, status: string, last_date: ?string, detail?: string, note?: string}>
     */
    public function datasets(Project $project, string $group = ''): array
    {
        $coverage = SignalCoverage::summary($this->signals->collect($project->id, $project->workspaceId)->coverage);
        $datasets = [];
        foreach ($coverage as $source => $item) {
            [$label, $sourceGroup, $note] = self::SOURCES[$source] ?? [$source, 'other', null];
            if ($group !== '' && $sourceGroup !== $group) {
                continue;
            }
            $datasets[] = [
                'source' => $source,
                'label' => $label,
                'group' => $sourceGroup,
                'status' => CoverageLabel::status($item),
                'last_date' => ($item['date'] ?? '') !== '' ? $item['date'] : null,
                ...array_filter(['detail' => $item['notice'] ?? null, 'note' => $note], static fn(mixed $v): bool => $v !== null),
            ];
        }

        return $datasets;
    }

    /**
     * @param list<array{status: string}> $datasets
     * @return array{available: int, stale: int, partial: int, missing: int}
     */
    public static function summary(array $datasets): array
    {
        $counts = ['available' => 0, 'stale' => 0, 'partial' => 0, 'missing' => 0];
        foreach ($datasets as $dataset) {
            match ($dataset['status']) {
                'available' => ++$counts['available'],
                'stale' => ++$counts['stale'],
                'partial' => ++$counts['partial'],
                default => ++$counts['missing'],
            };
        }

        return $counts;
    }

    /**
     * The full inspection: datasets, import completeness, GA4 coverage and compatibility.
     *
     * @return array<string, mixed>
     */
    public function inspect(Project $project): array
    {
        $datasets = $this->datasets($project);
        $searchConsole = $this->searchConsole($project);
        $bing = $this->bingImports($project);
        $ga4 = $this->ga4Coverage($project);

        return [
            'checked_at_utc' => $this->clock->now()->format('Y-m-d H:i:s'),
            'summary' => self::summary($datasets),
            'datasets' => $datasets,
            'imports' => ['search_console' => $searchConsole, 'bing' => $bing, 'ga4' => $ga4],
            'business_measurement' => $this->businessMeasurement !== null ? ($this->businessMeasurement)($project) : null,
            'compatibility' => $this->compatibility($project, $searchConsole, $bing, $ga4, $datasets),
            'interpretation' => self::RULES,
        ];
    }

    /**
     * Daily Search Console import completeness over the last 90 imported days.
     *
     * @return array<string, mixed>
     */
    public function searchConsole(Project $project): array
    {
        $linked = $this->properties->linkFor($project->id) !== null;
        $span = $this->searchConsole->dataSpan($project->id);
        if ($span === null) {
            return ['state' => $linked ? 'never_imported' : 'not_linked', 'linked' => $linked, 'first' => null, 'last' => null];
        }
        $window = $this->window($span['first'], $span['last']);
        $covered = $this->searchConsole->coveredDates($project->id, $window);
        $daily = $this->searchConsole->daily($project->id, $window);
        $zeroDays = count(array_filter($daily, static fn($day): bool => $day->metrics->impressions === 0 && in_array($day->date, $covered, true)));
        $result = $this->completeness($window, $covered, $span['last'], self::SEARCH_CONSOLE_LAG);

        return [
            'linked' => $linked,
            'first' => $span['first'],
            'last' => $span['last'],
            ...$result,
            'zero_days' => $zeroDays,
            'state' => !$linked ? 'unlinked_with_data' : $result['state'],
            'notes' => [
                'Search Console days are Pacific time (America/Los_Angeles).',
                'A covered day with no impressions is a measured zero; a missing day was never imported completely and must not be read as zero.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bingImports(Project $project): array
    {
        $link = $this->bing->projectLink($project);
        $span = $this->bing->span($project->id);
        if ($link === null || $span === null) {
            return ['state' => $link === null ? 'not_linked' : 'never_imported', 'linked' => $link !== null, 'first' => null, 'last' => null];
        }
        $lag = $this->lag($span['last']);

        return [
            'state' => $lag > self::BING_LAG ? 'lagging' : 'ok',
            'linked' => true,
            'first' => $span['first'],
            'last' => $span['last'],
            'lag_days' => $lag,
            'expected_lag_days' => self::BING_LAG,
            'notes' => [
                'Bing keeps about six months. Daily totals have no position; queries and pages come in weekly buckets.',
            ],
        ];
    }

    /**
     * GA4 import completeness, sampling flags, key events and attribution limits.
     *
     * @return array<string, mixed>
     */
    public function ga4Coverage(Project $project): array
    {
        if (!$this->ga4->hasCredentials($project->workspaceId)) {
            return ['state' => 'not_connected', 'limitations' => self::GA4_LIMITS];
        }
        if ($this->ga4->propertyId($project->id) === null) {
            return ['state' => 'not_linked', 'limitations' => self::GA4_LIMITS];
        }
        $latest = $this->ga4->metadata($project->id);
        if ($latest === null) {
            return ['state' => 'never_imported', 'limitations' => self::GA4_LIMITS];
        }
        $to = min($latest['to'], $this->clock->now()->format('Y-m-d'));
        $from = max(substr($latest['from'], 0, 10), DateRange::endingOn($to, self::WINDOW_DAYS)->fromString());
        $window = new DateRange($from > $to ? $to : $from, $to);
        $days = $this->ga4->daily($project->id, $window);
        $covered = array_values(array_unique(array_map(static fn(array $d): string => $d['date'], $days)));
        sort($covered);
        $result = $this->completeness($window, $covered, $covered === [] ? $to : end($covered), self::GA4_LAG);
        $quality = $this->ga4->metadata($project->id, $window);
        $totals = $this->ga4->totals($project->id, $window);
        $events = $totals['sessions'] === 0 ? 'no_sessions' : ($totals['conversions'] > 0 ? 'recorded' : 'none_recorded');
        $revenue = $totals['sessions'] === 0 ? 'no_sessions' : ($totals['revenue'] != 0 ? 'recorded' : 'none_recorded');
        $state = $result['state'];
        if ($state === 'ok' && $quality !== null && ($quality['limited'] || $quality['thresholded'])) {
            $state = 'sampled';
        }

        return [
            ...$result,
            'state' => $state,
            'currency' => $latest['currency'],
            'timezone' => $latest['timezone'],
            'fetched_at' => $latest['fetched_at'],
            'limited' => $quality['limited'] ?? false,
            'thresholded' => $quality['thresholded'] ?? false,
            'sessions' => $totals['sessions'],
            'key_events' => $totals['conversions'],
            'revenue' => $totals['revenue'],
            'key_events_state' => $events,
            'revenue_state' => $revenue,
            'limitations' => self::GA4_LIMITS,
        ];
    }

    /**
     * Combinations that are easy to misread.
     *
     * @param array<string, mixed> $searchConsole
     * @param array<string, mixed> $bing
     * @param array<string, mixed> $ga4
     * @param list<array{source: string, status: string}> $datasets
     * @return list<array{level: string, title: string, detail: string}>
     */
    private function compatibility(Project $project, array $searchConsole, array $bing, array $ga4, array $datasets): array
    {
        $items = [];
        if (($searchConsole['state'] ?? '') === 'unlinked_with_data') {
            $items[] = ['level' => 'warning', 'title' => 'Search Console is no longer linked', 'detail' => 'Stored Search Console data remains, but nothing new is imported. Treat it as history, not as current performance.'];
        }
        if (($searchConsole['missing'] ?? 0) > 0) {
            $items[] = ['level' => 'warning', 'title' => 'Days missing from Search Console', 'detail' => sprintf('%d of %d days between %s and %s were never imported completely. Totals and comparisons over those days are incomplete, not zero.', $searchConsole['missing'], $searchConsole['days'], $searchConsole['from'], $searchConsole['to'])];
        }
        if (($ga4['missing'] ?? 0) > 0) {
            $items[] = ['level' => 'warning', 'title' => 'Days missing from GA4', 'detail' => sprintf('%d of %d days between %s and %s have no completed import in the current currency and timezone.', $ga4['missing'], $ga4['days'], $ga4['from'], $ga4['to'])];
        }
        if (($ga4['limited'] ?? false) === true || ($ga4['thresholded'] ?? false) === true) {
            $items[] = ['level' => 'warning', 'title' => 'GA4 data is limited or thresholded', 'detail' => 'Google withheld or grouped part of the landing-page rows. Page-level sessions, key events and revenue are lower bounds, and small pages may be missing entirely.'];
        }
        if (isset($ga4['timezone']) && is_string($ga4['timezone']) && $ga4['timezone'] !== $project->timezone) {
            $items[] = ['level' => 'info', 'title' => 'GA4 and the project use different timezones', 'detail' => sprintf('GA4 reports days in %s, the project uses %s. Daily GA4 numbers can shift by a day against other sources.', $ga4['timezone'], $project->timezone)];
        }
        if (($searchConsole['first'] ?? null) !== null && $project->timezone !== 'America/Los_Angeles') {
            $items[] = ['level' => 'info', 'title' => 'Search Console days are Pacific time', 'detail' => sprintf('Google counts each day in Pacific time; the project uses %s. Day-by-day comparisons with GA4 or rank checks can be offset by a day.', $project->timezone)];
        }
        if (($bing['linked'] ?? false) === true && ($searchConsole['first'] ?? null) !== null) {
            $items[] = ['level' => 'info', 'title' => 'Google and Bing are measured differently', 'detail' => 'Clicks and impressions can be added; positions cannot. Bing has no daily position and ends about three days earlier, so combined views use Google positions and the overlapping days only.'];
        }
        if (($ga4['sessions'] ?? 0) > 0 && ($searchConsole['first'] ?? null) !== null) {
            $items[] = ['level' => 'info', 'title' => 'GA4 sessions are not Search Console clicks', 'detail' => 'GA4 counts organic sessions from all search engines with consent; Search Console counts Google clicks. Compare their trends, not their totals.'];
        }
        if (($ga4['key_events_state'] ?? '') === 'none_recorded') {
            $items[] = ['level' => 'warning', 'title' => 'No key events from organic sessions', 'detail' => 'GA4 recorded organic sessions but no key events in the window. Either no event is marked as a key event in GA4, or none happened. Value estimates fall back to clicks.'];
        }
        foreach ($datasets as $dataset) {
            if ($dataset['status'] === 'partial') {
                $items[] = ['level' => 'info', 'title' => 'Analyses use a top sample', 'detail' => 'Opportunity analyses read the top 500 queries and pages by impressions. "Not found" in that sample does not mean a query or page has no traffic.'];
                break;
            }
        }

        return $items;
    }

    /**
     * Expected days are the window up to the last imported day; days after it
     * count as lag, not as gaps.
     *
     * @param list<string> $covered
     * @return array{state: string, from: string, to: string, days: int, covered: int, missing: int, missing_ranges: list<array{from: string, to: string, days: int}>, ranges_truncated: bool, lag_days: int, expected_lag_days: int}
     */
    private function completeness(DateRange $window, array $covered, string $last, int $expectedLag): array
    {
        $have = array_fill_keys($covered, true);
        $ranges = [];
        $missing = 0;
        $start = $previous = null;
        $run = 0;
        foreach ([...$window->eachDay(), null] as $day) {
            $gap = $day !== null && $day <= $last && !isset($have[$day]);
            if ($gap && $previous !== null && (new \DateTimeImmutable($previous))->modify('+1 day')->format('Y-m-d') === $day) {
                $previous = $day;
                ++$run;
                ++$missing;
                continue;
            }
            if ($start !== null && $previous !== null) {
                $ranges[] = ['from' => $start, 'to' => $previous, 'days' => $run];
            }
            $start = $previous = $gap ? $day : null;
            $run = $gap ? 1 : 0;
            $missing += $gap ? 1 : 0;
        }
        $lag = $this->lag($last);
        $days = count(array_filter($window->eachDay(), static fn(string $d): bool => $d <= $last));

        return [
            'state' => $missing > 0 ? 'gaps' : ($lag > $expectedLag ? 'lagging' : 'ok'),
            'from' => $window->fromString(),
            'to' => min($window->toString(), $last),
            'days' => $days,
            'covered' => $days - $missing,
            'missing' => $missing,
            'missing_ranges' => array_slice(array_reverse($ranges), 0, self::MAX_RANGES),
            'ranges_truncated' => count($ranges) > self::MAX_RANGES,
            'lag_days' => $lag,
            'expected_lag_days' => $expectedLag,
        ];
    }

    private function window(string $first, string $last): DateRange
    {
        $from = DateRange::endingOn($last, self::WINDOW_DAYS)->fromString();

        return new DateRange(max($first, $from), $last);
    }

    private function lag(string $last): int
    {
        $today = new \DateTimeImmutable($this->clock->now()->format('Y-m-d'));

        return max(0, (int) (new \DateTimeImmutable(substr($last, 0, 10)))->diff($today)->format('%r%a'));
    }
}
