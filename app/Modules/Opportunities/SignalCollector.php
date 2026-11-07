<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities;

use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\DimensionRows;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\AiVisibility\AiOverviewKeyword;
use SeoControlCenter\Modules\AiVisibility\AiOverviewRepository;
use SeoControlCenter\Modules\Bing\BingService;
use SeoControlCenter\Modules\Local\LocalRepository;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * Reads the stored data the opportunity rules need (local tables only,
 * never a provider call): the last 28 days of Search Console data and the
 * preceding 28, the latest rank check and the one about 7 days earlier,
 * competitor gaps, the latest site audit, and the latest stored keyword
 * research, backlink lookups, AI Overviews and AI mentions, and local check.
 *
 * With a linked Bing site, Bing's queries and pages for the same days are
 * added to Google's per query and URL (positions weighted by impressions),
 * so the rules see the demand of both engines. Page declines compare
 * Google only: Bing's weekly buckets do not split into two exact windows.
 */
final readonly class SignalCollector
{
    public const int WINDOW_DAYS = 28;
    public const int ROW_LIMIT = 500;
    public const int KEYWORD_LIMIT = 1000;
    private const int COMPARE_DAYS = 7;
    /** Bing crawl history read for index and crawl-error trends. */
    private const int BING_CRAWL_DAYS = 60;

    public function __construct(
        private SearchPerformanceReader $searchConsole,
        private RankingRepository $rankings,
        private CompetitorRepository $competitors,
        private BingService $bing,
        private SiteAuditRepository $siteAudits,
        private ResearchRepository $research,
        private AiOverviewRepository $aiOverviews,
        private LocalRepository $local,
        private \SeoControlCenter\Modules\WebVitals\WebVitalsRepository $vitals,
        private \SeoControlCenter\Core\Support\Clock $clock,
        private \SeoControlCenter\Domain\Opportunities\ConfidenceReader $confidence,
        private \SeoControlCenter\Modules\Analytics\Ga4Repository $ga4,
        private \SeoControlCenter\Modules\Pages\PageRepository $pageRepository,
        private \SeoControlCenter\Domain\Opportunities\TrendReader $trends,
        private \SeoControlCenter\Domain\Opportunities\PanelSignalReader $panels,
        private \SeoControlCenter\Domain\Opportunities\OutcomeRepository $outcomes,
        private \SeoControlCenter\Domain\Opportunities\BusinessContextRepository $business,
        private \SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository $serps,
        private \SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras $extras,
        private \SeoControlCenter\Modules\Bing\BingDataRepository $bingData,
        private ?\SeoControlCenter\Domain\Comparison\AnalysisRepository $comparisons = null,
        private ?\SeoControlCenter\Modules\IndexNow\IndexNowRepository $pageDates = null,
        private ?\SeoControlCenter\Domain\Clusters\ClusterRepository $clusters = null,
        private ?\SeoControlCenter\Domain\Projects\ProjectRepository $projects = null,
        private ?\SeoControlCenter\Modules\AiVisibility\AiCrawlerRepository $aiCrawlers = null,
        private ?\SeoControlCenter\Modules\ContentInventory\OverlapReviewRepository $overlaps = null,
        private ?\SeoControlCenter\Modules\Local\LocationRepository $localLocations = null,
    ) {}

    public function collect(int $projectId, int $workspaceId): ProjectSignals
    {
        $window = null;
        $queries = $pages = [];
        $googleQueries = [];
        $bingQueries = [];
        $bingPages = [];
        $googlePages = [];
        $searchSources = [];
        $bingInWindow = false;
        $previousPages = [];
        $trendSnapshot = null;
        $span = $this->searchConsole->dataSpan($projectId);
        $bingSpan = $this->bing->span($projectId);
        $googleComplete = $span !== null && $this->searchConsole->coveredDays($projectId, new DateRange($span['last'], $span['last'])) === 1;
        $googleFresh = $googleComplete && $span !== null && \SeoControlCenter\Domain\Opportunities\SignalCoverage::at($span['last'], $this->clock->now(), 7)['status'] === 'available';
        $bingFresh = $bingSpan !== null && \SeoControlCenter\Domain\Opportunities\SignalCoverage::at($bingSpan['last'], $this->clock->now(), 10)['status'] === 'available';
        $range = match (true) {
            $googleFresh && $span !== null => DateRange::endingOn($span['last'], self::WINDOW_DAYS),
            $bingFresh && $bingSpan !== null => DateRange::endingOn($bingSpan['last'], self::WINDOW_DAYS),
            default => null,
        };
        if ($range !== null) {
            $window = $range->fromString() . ' – ' . $range->toString();
            if ($googleFresh) {
                $searchSources[] = 'search_console';
                $trendSnapshot = $this->trends->snapshot($projectId, $range);
                $queries = $trendSnapshot->queries;
                $googleQueries = $queries;
                $pages = $trendSnapshot->pages;
                $googlePages = $pages;
            }
            if ($bingFresh && $bingSpan !== null && $bingSpan['first'] <= $range->toString() && $bingSpan['last'] >= $range->fromString()) {
                $bingInWindow = true;
                $searchSources[] = 'bing';
                $bingQueries = $this->bing->top($projectId, PerformanceDimension::Query, $range, self::ROW_LIMIT);
                $queries = DimensionRows::merge($queries, $bingQueries);
                $bingPages = $this->bing->top($projectId, PerformanceDimension::Page, $range, self::ROW_LIMIT);
                $pages = DimensionRows::merge($pages, $bingPages);
                $window .= $googleFresh ? ', Google + Bing' : ', Bing';
            }
        }
        if ($googleFresh && $span !== null && $range !== null) {
            $previousPages = $trendSnapshot->previousPages ?? [];
        }

        $settings = $this->rankings->settings($projectId);
        $latest = $this->rankings->latestDay($projectId, $settings->device);
        $keywords = $moves = $gaps = [];
        $compareDate = null;
        if ($latest !== null) {
            $keywords = $this->rankings->keywords($projectId, $workspaceId, $settings->device, self::KEYWORD_LIMIT, 0);
            $compare = $this->rankings->dayOnOrBefore($projectId, $settings->device, (new \DateTimeImmutable($latest->date))->modify(sprintf('-%d days', self::COMPARE_DAYS))->format('Y-m-d'));
            if ($compare !== null) {
                $compareDate = $compare->date;
                $moves = $this->rankings->moves($projectId, $settings->device, $compare->date, $latest->date);
            }
            foreach ($this->competitors->forProject($projectId) as $competitor) {
                foreach ($this->competitors->compare($projectId, $workspaceId, $competitor->id, $settings->device, $latest->date) as $comparison) {
                    if ($comparison->isGap()) {
                        $gaps[] = ['competitor' => $competitor->domain, 'comparison' => $comparison];
                    }
                }
            }
        }

        [$auditDate, $siteIssues] = $this->siteIssues($projectId);
        $domain = $this->rankings->projectDomain($projectId, $workspaceId);
        $domain = $domain !== null ? (string) preg_replace('/^www\./', '', strtolower($domain)) : null;

        $ranked = $this->research->find($projectId, 'ranked');
        $researchGaps = [];
        $gapDate = null;
        $gapComplete = true;
        foreach ($this->research->forKind($projectId, 'gap') as $gap) {
            $gapComplete = $gapComplete && $gap->status === 'done';
            $competitor = $gap->params['competitor'] ?? null;
            if ($gap->fetchedAt === null || !is_string($competitor)) {
                continue;
            }
            $gapDate = $gapDate === null ? $gap->fetchedAt : min($gapDate, $gap->fetchedAt);
            foreach ($gap->keywords() as $keyword) {
                $researchGaps[] = ['competitor' => $competitor, 'keyword' => $keyword];
            }
        }
        $researchDate = $ranked->fetchedAt ?? ($researchGaps !== [] ? 'stored lookups' : null);

        $profiles = $this->research->find($projectId, 'bl_profiles');
        $linkGap = $this->research->find($projectId, 'bl_gap');
        $lost = $this->research->find($projectId, 'bl_lost');
        $backlinksDate = $profiles->fetchedAt ?? $linkGap->fetchedAt ?? $lost->fetchedAt ?? null;

        $aiDate = $this->aiOverviews->latestDate($projectId, $settings->device);
        $aiOverviews = $aiDate === null ? [] : array_map(
            static fn(AiOverviewKeyword $k): array => ['keyword' => $k->keyword, 'position' => $k->organicPosition, 'cited' => $k->ownPosition !== null, 'domains' => $k->domains],
            $this->aiOverviews->keywords($projectId, $settings->device, $aiDate, true, self::KEYWORD_LIMIT, 0),
        );
        $mentions = $this->research->find($projectId, 'llm');
        $ideas = $this->research->find($projectId, 'ideas');
        $suggestions = $this->research->find($projectId, 'suggestions');
        $strength = $this->research->find($projectId, 'serp_strength');
        $answers = $this->research->find($projectId, 'llm_answers');
        $serps = $this->serps->latest($projectId, $settings->device);
        $serpDate = $serps !== [] ? max(array_map(static fn(\SeoControlCenter\Domain\Opportunities\SerpLandscape $s): string => $s->checkedOn, $serps)) : null;
        // AI Overviews on the check closest to the end of the preceding Search Console window, for "appeared since" context.
        $earlierDate = null;
        $earlierOverviews = [];
        if ($aiDate !== null) {
            $earlierDate = $this->aiOverviews->dateOnOrBefore($projectId, $settings->device, $range !== null ? $range->previous()->toString() : (new \DateTimeImmutable($aiDate))->modify('-28 days')->format('Y-m-d'));
            if ($earlierDate !== null && $earlierDate < $aiDate) {
                foreach ($this->aiOverviews->keywords($projectId, $settings->device, $earlierDate, true, self::KEYWORD_LIMIT, 0) as $row) {
                    $earlierOverviews[\SeoControlCenter\Domain\Keywords\Keyword::normalize($row->keyword)] = true;
                }
            } else {
                $earlierDate = null;
            }
        }

        $localSettings = $this->local->settings($projectId);
        $localDate = $localSettings !== null ? $this->local->latestDate($projectId) : null;
        $localRankings = [];
        if ($localDate !== null) {
            $names = array_column($this->local->keywords($projectId), 'keyword', 'id');
            foreach ($this->local->rankings($projectId, $localDate) as $keywordId => $result) {
                if (isset($names[$keywordId])) {
                    $localRankings[] = ['keyword' => $names[$keywordId], 'position' => $result['position'], 'top' => $result['top']];
                }
            }
        }
        $profile = $localSettings !== null ? ($this->local->profiles($projectId, 1)[0]['profile'] ?? null) : null;
        // The profile against the website and the imported reviews (0.46.0).
        $napCheck = null;
        if ($profile !== null) {
            $napAudit = $this->siteAudits->latest($projectId, 'completed');
            $napCheck = \SeoControlCenter\Domain\Local\NapCheck::compare($profile, $domain, $napAudit !== null ? array_map(static fn(\SeoControlCenter\Modules\SiteAudit\AuditPage $p): array => ['url' => $p->url, 'business' => $p->business], $this->siteAudits->allPages($napAudit->id)) : null);
        }
        $reviewState = $localSettings?->cid !== null ? ($this->localLocations?->reviews($projectId, 'primary') ?? []) : [];
        $localReviews = ($reviewState['cid'] ?? null) === $localSettings?->cid && is_array($reviewState['items'] ?? null) && $reviewState['items'] !== [] && is_string($reviewState['fetched_at'] ?? null)
            ? ['items' => array_values($reviewState['items']), 'fetched' => substr($reviewState['fetched_at'], 0, 10)]
            : null;

        $now = $this->clock->now();
        $at = static fn(?string $date, int $days): array => \SeoControlCenter\Domain\Opportunities\SignalCoverage::at($date, $now, $days);
        $vitals = $this->vitals->list($projectId, 2000);
        $ga4Meta = $range !== null ? $this->ga4->metadata($projectId, $range) : null;
        $ga4State = $at($ga4Meta['fetched_at'] ?? null, 7);
        $ga4State['date'] = $range?->toString() ?? $ga4Meta['to'] ?? null;
        if ($range === null || !$this->ga4->hasCredentials($workspaceId) || $this->ga4->propertyId($projectId) === null || $ga4Meta === null || $ga4Meta['limited'] || $ga4Meta['thresholded'] || $this->ga4->coveredDays($projectId, $range) !== $range->days()) {
            $ga4State['status'] = 'missing';
        }
        $queryPageSpan = $this->pageRepository->queryPageSpan($projectId);
        $queryPageState = $at($queryPageSpan['last'] ?? null, 7);
        $queryPages = $googleFresh && $range !== null && $queryPageState['status'] === 'available' ? $this->pageRepository->queries($projectId, $range) : [];
        $coverage = [
            'query_sample' => ['status' => ($trendSnapshot !== null && $trendSnapshot->queryCount <= self::ROW_LIMIT || !$googleFresh && $bingInWindow) && count($bingQueries) < self::ROW_LIMIT ? 'available' : 'missing', 'date' => $span['last'] ?? $bingSpan['last'] ?? null, 'notice' => $trendSnapshot !== null ? sprintf('Analysis covers top %d of %d Google queries by impressions%s', min(self::ROW_LIMIT, $trendSnapshot->queryCount), $trendSnapshot->queryCount, count($bingQueries) >= self::ROW_LIMIT ? '; Bing sample may be truncated' : '') : 'Google query sample unavailable; Bing total unknown'],
            'page_sample' => ['status' => ($trendSnapshot !== null && $trendSnapshot->pageCount <= self::ROW_LIMIT || !$googleFresh && $bingInWindow) && count($bingPages) < self::ROW_LIMIT ? 'available' : 'missing', 'date' => $span['last'] ?? $bingSpan['last'] ?? null, 'notice' => $trendSnapshot !== null ? sprintf('Analysis covers top %d of %d Google pages by impressions%s', min(self::ROW_LIMIT, $trendSnapshot->pageCount), $trendSnapshot->pageCount, count($bingPages) >= self::ROW_LIMIT ? '; Bing sample may be truncated' : '') : 'Google page sample unavailable; Bing total unknown'],
            'google_page_sample' => ['status' => $trendSnapshot !== null && $trendSnapshot->pageCount <= self::ROW_LIMIT ? 'available' : 'missing', 'date' => $span['last'] ?? null, ...($trendSnapshot !== null ? ['notice' => sprintf('Analysis covers top %d of %d Google pages by impressions', min(self::ROW_LIMIT, $trendSnapshot->pageCount), $trendSnapshot->pageCount)] : [])],
            'ga4' => $ga4State,
            'query_pages' => $queryPageState,
            'search_console' => $googleComplete ? $at($span['last'] ?? null, 7) : ['status' => 'missing', 'date' => $span['last'] ?? null],
            'search_console_previous' => [...$at($previousPages !== [] ? ($span['last'] ?? null) : null, 7), 'date' => $range?->previous()->toString()],
            'bing' => $bingFresh && !$bingInWindow ? ['status' => 'missing', 'date' => $bingSpan['last'] ?? null] : $at($bingSpan['last'] ?? null, 10),
            'rankings' => $at($latest?->date, $settings->intervalDays() + 2),
            'ranking_comparison' => [...$at($compareDate !== null && $latest !== null && $compareDate >= (new \DateTimeImmutable($latest->date))->modify('-' . (self::COMPARE_DAYS + $settings->intervalDays() + 2) . ' days')->format('Y-m-d') ? $latest->date : null, $settings->intervalDays() + 2), 'date' => $compareDate],
            'seasonality' => $at($trendSnapshot?->seasonalRatios !== [] && $trendSnapshot !== null && $range !== null ? $range->to->modify('first day of this month')->modify('-1 month')->format('Y-m-d') : null, 75),
            'research_ranked' => $at($ranked?->status === 'done' ? $ranked->fetchedAt : null, 30),
            'research_gap' => $gapComplete ? $at($gapDate, 30) : ['status' => 'missing', 'date' => $gapDate],
            'backlink_profiles' => $at($profiles?->status === 'done' ? $profiles->fetchedAt : null, 30),
            'backlink_gap' => $at($linkGap?->status === 'done' ? $linkGap->fetchedAt : null, 30),
            'backlink_lost' => $at($lost?->status === 'done' ? $lost->fetchedAt : null, 30),
            'site_audit' => $at($auditDate, 30),
            'rendered_audit' => $at($this->research->find($projectId, \SeoControlCenter\Modules\SiteAudit\RenderedAuditService::KIND)?->fetchedAt, 30),
            'ai_overviews' => $at($aiDate, $settings->intervalDays() + 2),
            'ai_mentions' => $at($mentions?->status === 'done' ? $mentions->fetchedAt : null, 30),
            'research_ideas' => $at($ideas?->status === 'done' ? $ideas->fetchedAt : null, 30),
            'research_suggestions' => $at($suggestions?->status === 'done' ? $suggestions->fetchedAt : null, 30),
            'serp_landscape' => $at($serpDate, $settings->intervalDays() + 2),
            'serp_strength' => $at($strength?->status === 'done' ? $strength->fetchedAt : null, 30),
            'ai_answers' => $at($answers?->status === 'done' ? $answers->fetchedAt : null, 30),
            'local_rankings' => $at($localDate, 10),
            'local_profile' => $at($this->local->profiles($projectId, 1)[0]['date'] ?? null, 30),
            'local_reviews' => $at($localReviews['fetched'] ?? null, 60),
            'web_vitals' => $at($vitals !== [] ? max(array_map(static fn($r): string => $r->periodTo ?? '', $vitals)) : null, 35),
        ];
        // Other Google surfaces over the same windows as web search; sitemaps and Bing crawl counts as stored.
        $surfaces = [];
        if ($googleFresh && $range !== null) {
            $current = $this->extras->searchTypeTotals($projectId, $range);
            $before = $this->extras->searchTypeTotals($projectId, $range->previous());
            foreach ($current + $before as $type => $_) {
                $surfaces[$type] = ['now' => $current[$type] ?? \SeoControlCenter\Domain\SearchConsole\Metrics::zero(), 'previous' => $before[$type] ?? \SeoControlCenter\Domain\SearchConsole\Metrics::zero()];
            }
        }
        $coverage['search_console_surfaces'] = $googleFresh ? $at($this->extras->searchTypeLastDate($projectId), 7) : ['status' => 'missing', 'date' => $this->extras->searchTypeLastDate($projectId)];
        $sitemaps = $this->extras->sitemaps($projectId);
        $sitemapsAt = $this->extras->sitemapsRefreshedAt($projectId);
        $coverage['sitemaps'] = $at($sitemapsAt !== null ? substr($sitemapsAt, 0, 10) : null, 7);
        // The sitemap's last-modified dates, read by the IndexNow sitemap check (0.41.0).
        $pageUpdates = [];
        foreach ($this->pageDates?->knownUrls($projectId) ?? [] as $url => $lastmod) {
            if (is_string($lastmod) && preg_match('/^\d{4}-\d{2}-\d{2}/', $lastmod) === 1) {
                $pageUpdates[(string) $url] = substr($lastmod, 0, 10);
            }
        }
        // Topics & owners (0.42.0): each active topic's owner page and the other pages Google shows for it.
        $topics = [];
        $project = $this->clusters !== null ? array_find($this->projects?->allInWorkspace($workspaceId) ?? [], static fn(\SeoControlCenter\Domain\Projects\Project $p): bool => $p->id === $projectId) : null;
        foreach ($project !== null ? $this->clusters?->forProject($project, false) ?? [] : [] as $cluster) {
            if ($cluster->ownerUrl !== null && $cluster->retiredAt === null) {
                $share = null;
                foreach ($cluster->candidates as $candidate) {
                    if ($candidate['url'] === $cluster->ownerUrl) {
                        $share = (float) $candidate['share'];
                    }
                }
                $topics[] = ['label' => $cluster->label, 'owner' => $cluster->ownerUrl, 'manual' => $cluster->manualOwnerUrl !== null && $cluster->manualOwnerUrl === $cluster->ownerUrl, 'share' => $share, 'pages' => array_map(static fn(array $c): array => ['url' => $c['url'], 'clicks' => $c['clicks'], 'impressions' => $c['impressions']], $cluster->candidates)];
            }
        }
        $pageDatesAt = $this->pageDates?->settings($projectId)?->sitemapCheckedAt;
        $coverage['page_dates'] = $pageUpdates === [] ? ['status' => 'missing', 'date' => null, 'notice' => 'No sitemap last-modified dates: set up the IndexNow sitemap check, with a sitemap that lists lastmod.'] : $at($pageDatesAt !== null ? substr($pageDatesAt, 0, 10) : null, 14);
        // AI crawler access (0.43.0): the weekly robots.txt check; an unreadable robots.txt proves nothing.
        $crawlerCheck = $this->aiCrawlers?->latest($projectId);
        $coverage['ai_crawlers'] = match (true) {
            $crawlerCheck === null => ['status' => 'missing', 'date' => null, 'notice' => 'Not checked yet: the weekly check runs within a day, or use Check now under AI visibility → AI crawler access.'],
            $crawlerCheck->robotsState === 'unreachable' => ['status' => 'missing', 'date' => substr($crawlerCheck->checkedAt, 0, 10), 'notice' => 'robots.txt could not be read at the last check, so no crawler was evaluated.'],
            default => $at(substr($crawlerCheck->checkedAt, 0, 10), 10),
        };
        // Near-duplicate pages (0.44.0): the overlap review's text check on the latest site audit, minus pairs a person kept separate.
        $nearDuplicates = [];
        if ($this->overlaps !== null) {
            $texts = $this->overlaps->pageTexts($projectId, \SeoControlCenter\Modules\ContentInventory\OverlapService::MAX_PAGES);
            $reviews = $this->overlaps->reviews($projectId);
            // Different products often share a description (one cultivar from two breeders); they are never merged (0.44.1, finding 39).
            $products = array_fill_keys($this->overlaps->productUrls($projectId), true);
            foreach (\SeoControlCenter\Domain\ContentInventory\OverlapFinder::nearDuplicates($texts['pages']) as $candidate) {
                $decision = $reviews[$candidate['key']]['decision'] ?? null;
                $product = array_filter($candidate['members'], static fn(string $url): bool => isset($products[$url])) !== [];
                if ($decision !== 'keep_separate' && $candidate['distinct_purpose'] === null && !$product) {
                    $nearDuplicates[] = ['key' => $candidate['key'], 'members' => $candidate['members'], 'evidence' => $candidate['evidence'], 'uncertainty' => $candidate['uncertainty'], 'score' => $candidate['score'], 'decision' => $decision];
                }
            }
            $coverage['near_duplicates'] = $at($texts['audit_at'] !== null ? substr($texts['audit_at'], 0, 10) : null, 30);
        }
        $analyses = $this->comparisons?->latestDone($projectId, $now->modify('-30 days')->format('Y-m-d H:i:s')) ?? [];
        $coverage['competitor_analysis'] = $at($analyses !== [] ? max(array_map(static fn($a): string => substr((string) $a->finishedAt, 0, 10), $analyses)) : null, 30);
        $bingCrawl = [];
        if ($this->bingData->link($projectId) !== null) {
            foreach ($this->bingData->crawlStats($projectId, DateRange::endingOn($now->format('Y-m-d'), self::BING_CRAWL_DAYS)) as $day) {
                $bingCrawl[] = ['date' => $day->date, 'inIndex' => $day->inIndex, 'crawled' => $day->crawledPages, 'errors' => $day->crawlErrors, 'code4xx' => $day->code4xx, 'code5xx' => $day->code5xx, 'blocked' => $day->blockedByRobots];
            }
        }
        $coverage['bing_crawl'] = $at($bingCrawl !== [] ? $bingCrawl[count($bingCrawl) - 1]['date'] : null, 10);

        $panel = $this->panels->snapshot($projectId, $workspaceId, $range ?? DateRange::endingOn($now->format('Y-m-d'), self::WINDOW_DAYS), $now->format('Y-m-d'));
        $coverage = [...$coverage, ...$panel->coverage];
        $coverage['outcomes'] = ['status' => 'available', 'date' => $now->format('Y-m-d')];
        $coverage['rank_scope'] = ['status' => 'available', 'date' => $now->format('Y-m-d')];
        foreach ($keywords as $row) {
            $state = $at($row->checkedOn, $settings->intervalDays() + 2);
            $coverage['rank_record:' . hash('sha256', $row->publicId)] = $state;
            $coverage['rank_query:' . hash('sha256', \SeoControlCenter\Domain\Keywords\Keyword::normalize($row->keyword))] = $state;
        }
        $coverage['vitals_scope'] = ['status' => 'available', 'date' => $now->format('Y-m-d')];
        foreach ($vitals as $row) {
            $coverage['vitals_record:' . hash('sha256', $row->url)] = $at($row->periodTo, 35);
        }
        $brandName = $trendSnapshot?->projectName;
        $brandTerms = $brandName !== null && !\SeoControlCenter\Domain\Opportunities\Rules\BrandSearchRule::isGenericName($brandName) ? [$brandName] : [];

        return new ProjectSignals(
            $window,
            array_values(array_filter($queries, static fn(DimensionRow $r): bool => $r->key !== '')),
            $pages,
            $previousPages,
            $latest?->date,
            $compareDate,
            $keywords,
            $moves,
            $gaps,
            $auditDate,
            $siteIssues,
            $domain,
            $researchDate !== null ? substr($researchDate, 0, 10) : null,
            array_values(array_filter($ranked?->fetchedAt !== null ? $ranked->keywords() : [], static fn(ResearchKeyword $k): bool => $k->position !== null)),
            $researchGaps,
            $backlinksDate !== null ? substr($backlinksDate, 0, 10) : null,
            $profiles?->fetchedAt !== null ? $profiles->backlinkProfiles() : [],
            $linkGap?->fetchedAt !== null ? $linkGap->referringDomains() : [],
            $lost?->fetchedAt !== null ? $lost->referringDomains() : [],
            count($this->competitors->forProject($projectId)),
            $aiDate,
            $aiOverviews,
            $mentions?->fetchedAt !== null ? $mentions->mentions() : [],
            $localDate,
            $localSettings?->businessName,
            $localRankings,
            $profile,
            array_values(array_filter($vitals, fn(\SeoControlCenter\Domain\WebVitals\WebVitalRecord $r): bool => $r->periodTo !== null && $r->periodTo >= $this->clock->now()->modify('-35 days')->format('Y-m-d') && $r->periodTo <= $this->clock->now()->format('Y-m-d'))),
            $coverage,
            \SeoControlCenter\Domain\Opportunities\CtrModel::train($googleQueries, $domain, $brandTerms),
            $searchSources,
            $googlePages,
            [...$this->confidence->observations($projectId, $settings->device, $range ?? DateRange::endingOn($now->format('Y-m-d'), self::WINDOW_DAYS)), ...array_map(static fn(DimensionRow $row): \SeoControlCenter\Domain\Opportunities\ConfidenceObservation => new \SeoControlCenter\Domain\Opportunities\ConfidenceObservation('query', $row->key, 'bing', (int) $row->metrics->impressions, (int) $row->metrics->clicks, $row->metrics->position, null, 1, $bingSpan['last'] ?? ''), $bingQueries)],
            $now->format('Y-m-d'),
            ['search_console' => 7, 'search_console_previous' => 7, 'bing' => 10, 'rankings' => $settings->intervalDays() + 2, 'ranking_comparison' => $settings->intervalDays() + 2, 'ai_overviews' => $settings->intervalDays() + 2, 'serp_landscape' => $settings->intervalDays() + 2, 'local_rankings' => 10, 'web_vitals' => 35, 'search_console_surfaces' => 7, 'sitemaps' => 7, 'bing_crawl' => 10, 'page_dates' => 14],
            $ga4State['status'] === 'available' && $range !== null ? $this->ga4->landingPages($projectId, $range, 2000) : [],
            $queryPages,
            $ga4Meta['currency'] ?? null,
            $ga4State['status'] === 'available' && $range !== null ? $this->ga4->totals($projectId, $range) : null,
            $trendSnapshot,
            $panel,
            $this->outcomes->factors($projectId, $now->modify('-365 days')->format('Y-m-d')),
            $this->business->find($projectId),
            $this->business->ratings($projectId),
            $coverage['serp_landscape']['status'] === 'available' ? $serps : [],
            $coverage['serp_strength']['status'] === 'available' && $strength !== null ? $strength->strengths() : [],
            $ideas?->fetchedAt !== null ? $ideas->keywords() : [],
            $suggestions?->fetchedAt !== null ? $suggestions->keywords() : [],
            $earlierOverviews,
            $earlierDate,
            $coverage['ai_answers']['status'] === 'available' && $answers !== null ? $answers->samples() : [],
            $answers?->fetchedAt !== null ? substr($answers->fetchedAt, 0, 10) : null,
            self::brandNames($brandTerms, $domain),
            $coverage['search_console_surfaces']['status'] === 'available' ? $surfaces : [],
            $coverage['sitemaps']['status'] === 'available' ? $sitemaps : [],
            $coverage['bing_crawl']['status'] === 'available' ? $bingCrawl : [],
            $coverage['competitor_analysis']['status'] === 'available' ? $analyses : [],
            $coverage['page_dates']['status'] === 'available' ? $pageUpdates : [],
            $topics,
            $coverage['ai_crawlers']['status'] === 'available' ? $crawlerCheck : null,
            ($coverage['near_duplicates']['status'] ?? 'missing') === 'available' ? $nearDuplicates : [],
            $napCheck,
            $localReviews,
        );
    }

    /**
     * The project's own brand words: its name (unless generic) and the main
     * label of its domain.
     *
     * @param list<string> $brandTerms
     * @return list<string>
     */
    private static function brandNames(array $brandTerms, ?string $domain): array
    {
        $names = array_map(\SeoControlCenter\Domain\Keywords\Keyword::normalize(...), $brandTerms);
        $label = $domain !== null ? explode('.', $domain)[0] : '';
        if (mb_strlen($label) >= 3 && !in_array($label, ['www', 'shop', 'store', 'blog', 'app', 'my', 'the', 'get', 'try'], true)) {
            $names[] = $label;
        }

        return array_values(array_unique(array_filter($names, static fn(string $n): bool => $n !== '')));
    }

    /**
     * Issue types of the latest completed site audit, with up to three example pages.
     *
     * @return array{0: ?string, 1: list<array{type: string, label: string, severity: string, advice: string, pages: int, examples: list<string>, effort: int}>}
     */
    private function siteIssues(int $projectId): array
    {
        $audit = $this->siteAudits->latest($projectId, 'completed');
        $rendered = $this->research->find($projectId, \SeoControlCenter\Modules\SiteAudit\RenderedAuditService::KIND);
        $issues = \SeoControlCenter\Modules\SiteAudit\RenderedFindings::issues($rendered);
        foreach ($audit !== null ? $this->siteAudits->issueCounts($audit->id) : [] as $value => $count) {
            if ($audit === null) {
                continue;
            }
            $type = IssueType::tryFrom($value);
            // Notices are not findings, except the growth notices the opportunity rule reports.
            if ($type === null || ($type->severity() === 'notice' && !in_array($type->value, \SeoControlCenter\Domain\Opportunities\Rules\SiteIssueRule::GROWTH_NOTICES, true))) {
                continue;
            }
            $issues[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'severity' => $type->severity(),
                'advice' => $type->advice(),
                'pages' => $count,
                'examples' => array_map(
                    static fn(array $row): string => (string) preg_replace('#^https?://[^/]+#', '', $row['url']) ?: '/',
                    $this->siteAudits->issues($audit->id, $type, 0, 3),
                ),
                'effort' => match ($type) {
                    IssueType::SlowPage => Opportunity::EFFORT_HIGH,
                    IssueType::BrokenPage, IssueType::ServerError, IssueType::Unreachable, IssueType::LargePage, IssueType::DuplicateDescription, IssueType::DuplicateTitle => Opportunity::EFFORT_MEDIUM,
                    default => Opportunity::EFFORT_LOW,
                },
            ];
        }

        $date = $audit !== null ? ($audit->finishedAt ?? $audit->createdAt) : $rendered?->fetchedAt;
        return [$date !== null ? substr($date, 0, 10) : null, $issues];
    }
}
