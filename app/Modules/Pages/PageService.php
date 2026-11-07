<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Bing\BingDataRepository;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/** Called only after ProjectService or ToolContext has authorized the project. */
final readonly class PageService
{
    public function __construct(
        private PageRepository $pages,
        private SearchPerformanceReader $google,
        private BingDataRepository $bing,
        private SiteAuditRepository $audits,
        private OpportunityRepository $opportunities,
        private ResearchRepository $research,
        private PageInsights $insights,
        private Clock $clock,
        private \SeoControlCenter\Modules\WebVitals\WebVitalsRepository $vitals,
        private \SeoControlCenter\Modules\Analytics\Ga4Repository $ga4,
        private \SeoControlCenter\Modules\Annotations\AnnotationService $annotations,
        private \SeoControlCenter\Modules\Indexing\IndexingService $indexing,
        private ?\SeoControlCenter\Modules\Annotations\ResultsLedgerService $ledger = null,
        private ?\SeoControlCenter\Domain\Comparison\AnalysisRepository $analyses = null,
    ) {}

    public function range(Project $project): DateRange
    {
        $google = $this->google->dataSpan($project->id);
        $bing = $this->bing->dataSpan($project->id);
        $end = max($google['last'] ?? '', $bing['last'] ?? '');
        return DateRange::endingOn($end !== '' ? $end : $this->clock->now()->format('Y-m-d'), 28);
    }

    /** @return array<string, mixed> */
    public function index(Project $project, int $page = 1, string $search = '', string $sort = 'url', int $perPage = 50): array
    {
        $range = $this->range($project);
        $search = self::search($search);
        $sort = self::sort($sort);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
        $total = $this->pages->count($project->id, $search);
        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pageCount, $page));
        $queries = $this->pages->queries($project->id, $range);
        return ['rows' => $this->pages->list($project->id, $range, $perPage, ($page - 1) * $perPage, $search, $sort),
            'total' => $total, 'page' => $page, 'pageCount' => $pageCount, 'perPage' => $perPage, 'search' => $search, 'sort' => $sort, 'range' => $range,
            'queryPageSpan' => $this->pages->queryPageSpan($project->id),
            'cannibalization' => $this->insights->cannibalization($queries), 'analysisLimited' => count($queries) >= 5000];
    }

    /** Matching URLs exported directly; larger exports are queued (PageExportService). */
    public const int DIRECT_EXPORT_LIMIT = 10000;

    /** @return array{rows: list<list<string|int|float|null>>, range: DateRange} */
    public function export(Project $project, string $search = '', string $sort = 'url'): array
    {
        $search = self::search($search);
        $sort = self::sort($sort);
        $tooLarge = 'Direct CSV export supports up to 10,000 matching URLs. Use Prepare CSV export for larger results.';
        if ($this->pages->count($project->id, $search) > self::DIRECT_EXPORT_LIMIT) {
            throw new HttpException(422, $tooLarge);
        }
        $range = $this->range($project);
        $pages = $this->pages->export($project->id, $range, $search, $sort);
        if (count($pages) > self::DIRECT_EXPORT_LIMIT) {
            throw new HttpException(422, $tooLarge);
        }
        return ['rows' => $this->exportRows($pages, $range), 'range' => $range];
    }

    /**
     * @param list<PageSummary> $pages
     * @return list<list<string|int|float|null>>
     */
    public function exportRows(array $pages, DateRange $range): array
    {
        return [self::EXPORT_HEADER, ...array_map(static fn(PageSummary $page): array => self::exportRow($page, $range), $pages)];
    }

    public const array EXPORT_HEADER = ['URL', 'Google clicks', 'Bing clicks', 'Google impressions', 'Period from', 'Period to'];

    /** @return list<string|int|null> */
    public static function exportRow(PageSummary $page, DateRange $range): array
    {
        return [$page->url, $page->google?->clicks, $page->bing?->clicks, $page->google?->impressions, $range->fromString(), $range->toString()];
    }

    public static function search(string $search): string
    {
        return mb_substr(trim($search), 0, 200);
    }

    public static function sort(string $sort): string
    {
        return in_array($sort, ['url', 'google_clicks', 'bing_clicks', 'impressions'], true) ? $sort : 'url';
    }

    /** @return array<string, mixed> */
    public function details(Project $project, string $url): array
    {
        if ($url === '' || mb_strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new HttpException(400, 'Choose a valid page URL.');
        }
        $range = $this->range($project);
        $summary = $this->pages->find($project->id, $url, $range);
        if ($summary === null) {
            throw new HttpException(404, 'Page not found.');
        }
        $queries = $this->pages->queries($project->id, $range, $url);
        $rankings = $this->pages->rankings($project->id, $url);
        $queryNames = array_column($rankings, 'keyword');
        foreach ($queries as $query) {
            $queryNames[] = $query->query;
        }
        $audit = $this->audits->latest($project->id, 'completed');
        $crawled = $audit !== null ? $this->audits->findPage($audit->id, $url) : null;
        $issues = $crawled !== null && $audit !== null ? $this->audits->pageIssues($audit->id, $crawled->id) : [];
        $nearTop = array_map(static fn(PageQuery $q): string => $q->query, array_values(array_filter($queries, static fn(PageQuery $q): bool => $q->metrics->position >= 4 && $q->metrics->position <= 20 && $q->metrics->impressions >= 50)));
        foreach ($rankings as $ranking) {
            if ($ranking['position'] !== null && $ranking['position'] >= 4 && $ranking['position'] <= 20) {
                $nearTop[] = $ranking['keyword'];
            }
        }
        $links = $audit !== null && $crawled !== null ? $this->insights->internalLinks(
            $crawled,
            $this->audits->allPages($audit->id),
            $this->audits->inboundCounts($audit->id),
            $this->audits->linkSources($audit->id, $crawled->id, 2000),
            $nearTop
        ) : [];
        $open = [];
        $issueTypes = array_map(static fn(array $r): string => $r['type']->value, $issues);
        foreach ($this->opportunities->list($project->id, StoredOpportunity::OPEN, null, 1000, 0) as $opportunity) {
            foreach ($opportunity->evidence as $evidence) {
                if ($evidence['value'] === $url || ($evidence['label'] === 'Issue type' && in_array($evidence['value'], $issueTypes, true)) || (in_array(mb_strtolower($evidence['label']), ['query', 'keyword'], true) && in_array($evidence['value'], $queryNames, true))) {
                    $open[] = ['id' => $opportunity->publicId, 'title' => $opportunity->title, 'explanation' => $opportunity->explanation, 'priority' => $opportunity->priority];
                    break;
                }
            }
        }
        $allQueries = $this->pages->queries($project->id, $range);
        // What the page could gain (0.47.0): clicks at position 3 on the site's own curve, the pages above it, its measured changes.
        $ctr = \SeoControlCenter\Domain\Opportunities\CtrModel::train(array_map(static fn(PageQuery $q): \SeoControlCenter\Domain\SearchConsole\DimensionRow => new \SeoControlCenter\Domain\SearchConsole\DimensionRow($q->query, $q->metrics), $allQueries), $project->primaryDomain);
        $competitors = $this->analyses !== null ? PageOutlook::compare($this->analyses->latestDone($project->id, $this->clock->now()->modify('-180 days')->format('Y-m-d H:i:s')), $url) : null;
        $cannibalization = array_values(array_filter($this->insights->cannibalization($allQueries), static fn(array $group): bool => in_array($url, array_column($group['pages'], 'url'), true)));
        $backlinks = [];
        $backlinkDates = [];
        foreach (['bl_links', 'bl_lost_links'] as $kind) {
            $result = $this->research->find($project->id, $kind);
            if ($result?->fetchedAt !== null) {
                $backlinkDates[$kind] = $result->fetchedAt;
                foreach ($result->pageLinks() as $link) {
                    if ($link->targetUrl === $url) {
                        $backlinks[] = [...$link->toArray(), 'status' => $kind === 'bl_lost_links' ? 'lost' : 'live'];
                    }
                }
            }
        }
        $analytics = $this->ga4->metadata($project->id, $range);
        $previousRange = $range->previous();
        $complete = $this->pages->importedPageDays($project->id, $range) === $range->days()
            && $this->pages->importedPageDays($project->id, $previousRange) === $previousRange->days();
        $previous = $complete ? $this->pages->find($project->id, $url, $previousRange)?->google : null;
        $details = ['url' => $url, 'range' => ['from' => $range->fromString(), 'to' => $range->toString()],
            'annotations' => array_map(static fn(\SeoControlCenter\Modules\Annotations\Annotation $a): array => $a->present(), $this->annotations->context($project, $range, $url)),
            'annotation_notice' => \SeoControlCenter\Modules\Annotations\AnnotationService::CONTEXT,
            'traffic_comparison' => ['available' => $complete && $previous !== null && $summary->google !== null,
                'previous_range' => ['from' => $previousRange->fromString(), 'to' => $previousRange->toString()],
                'previous_google' => self::metrics($previous),
                'note' => 'Google page clicks only. Requires completed page imports for every day in both 28-day periods and stored metrics for this URL. Bing weekly buckets are not compared.'],
            'analytics' => $analytics === null ? null : ['metadata' => [...$analytics, 'from' => $range->fromString(), 'to' => $range->toString()], 'covered_days' => $this->ga4->coveredDays($project->id, $range), 'expected_days' => $range->days(), 'metrics' => $this->ga4->landingPages($project->id, $range, 1, $url)[0] ?? null],
            'google' => self::metrics($summary->google), 'bing' => self::metrics($summary->bing),
            'bing_note' => 'Weekly buckets ending in this range; not exact daily totals.',
            'query_page_span' => $this->pages->queryPageSpan($project->id),
            'search_queries' => array_map(static fn(PageQuery $q): array => ['query' => $q->query, ...(self::metrics($q->metrics) ?? [])], $queries),
            'rankings' => $rankings, 'ai_overview_citations' => $this->pages->citations($project->id, $url),
            'audit_finished_at' => $audit?->finishedAt,
            'crawl' => $crawled === null ? null : ['status' => $crawled->statusCode, 'title' => $crawled->title, 'description' => $crawled->description,
                'h1' => $crawled->h1, 'canonical' => $crawled->canonical, 'noindex' => $crawled->noindex, 'word_count' => $crawled->wordCount, 'depth' => $crawled->depth],
            'issues' => array_map(static fn(array $r): array => ['type' => $r['type']->value, 'label' => $r['type']->label(), 'severity' => $r['type']->severity(), 'advice' => $r['type']->advice(), 'detail' => $r['detail']], $issues),
            'opportunities' => $open, 'cannibalization' => $cannibalization, 'internal_link_suggestions' => $links,
            'click_potential' => PageOutlook::clickPotential($queries, $ctr),
            'click_basis' => $ctr->hasSamples() ? 'your site’s own clicks by position' : 'a general click curve (too few of your own searches yet)',
            'competitor_gap' => $competitors,
            'change_results' => array_map(static fn(array $r): array => [...$r, 'annotation' => $r['annotation']->present()], $this->ledger?->forUrl($project, $url) ?? []),
            'analysis_limited' => count($allQueries) >= 5000,
            'opportunities_limited' => $this->opportunities->count($project->id, StoredOpportunity::OPEN) > 1000,
            'web_vitals' => $this->vitals->find($project->id, $url)?->toArray(),
            'web_vitals_desktop' => $this->vitals->find($project->id, $url, 'DESKTOP')?->toArray(),
            'web_vitals_history' => array_map(static fn(\SeoControlCenter\Domain\WebVitals\WebVitalRecord $r): array => $r->toArray(), $this->vitals->history($project->id, $url)),
            'web_vitals_desktop_history' => array_map(static fn(\SeoControlCenter\Domain\WebVitals\WebVitalRecord $r): array => $r->toArray(), $this->vitals->history($project->id, $url, 'DESKTOP')),
            'web_vitals_weekly' => ['PHONE' => $this->vitals->watching($project->id, $url, 'PHONE'), 'DESKTOP' => $this->vitals->watching($project->id, $url, 'DESKTOP')],
            'google_indexing' => $this->indexing->saved($project, $url)?->present($this->indexing->staleBefore()),
            'google_indexing_notice' => \SeoControlCenter\Modules\Indexing\Inspection::NOTICE,
            'backlinks' => $backlinks, 'backlink_fetched_at' => $backlinkDates,
            'backlinks_note' => $backlinkDates === [] ? 'Fetch page links under Backlinks to see individual links.' : 'Stored sample: up to 1,000 live links and 1,000 links lost in the last 30 days for this project. Absence from this sample does not mean a page has no backlinks.'];
        $details['improvements'] = (new PageImprovements())->recommend($details);
        return $details;
    }

    /** @return array{clicks: int, impressions: int, position: float, ctr: float}|null */
    private static function metrics(?Metrics $metrics): ?array
    {
        return $metrics === null ? null : ['clicks' => $metrics->clicks, 'impressions' => $metrics->impressions, 'position' => $metrics->position, 'ctr' => $metrics->ctr()];
    }
}
