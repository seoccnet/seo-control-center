<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Competitors\KeywordComparison;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Local\BusinessProfile;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Domain\Local\NapCheck;
use SeoControlCenter\Domain\Rankings\RankedKeyword;
use SeoControlCenter\Domain\Rankings\RankingMove;
use SeoControlCenter\Domain\Research\AiMention;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;

/**
 * The stored data the opportunity rules look at, collected once per
 * analysis from every part of a project: Search Console and Bing, rank
 * checks, competitors, the site audit, keyword research, backlinks, AI
 * visibility and local SEO. Every part is optional: a project without some
 * of them simply gets fewer opportunities.
 */
final readonly class ProjectSignals
{
    /**
     * @param array<string, array{factor: float, samples: int}> $outcomeFactors
     * @param list<string> $searchSources
     * @param list<ConfidenceObservation> $confidenceObservations
     * @param array<string, int> $refreshDays
     * @param list<array{url: string, sessions: int, conversions: float, revenue: float}> $landingPages
     * @param list<\SeoControlCenter\Modules\Pages\PageQuery> $queryPages
     * @param array{sessions: int, conversions: float, revenue: float, pages: int}|null $ga4Totals
     * @param list<DimensionRow>|null $googlePages
     * @param array<string, array{status: string, date: ?string, notice?: string}> $coverage
     * @param list<DimensionRow> $queries Search Console queries, current window
     * @param list<DimensionRow> $pages Search Console pages, current window
     * @param array<string, Metrics> $previousPages page URL => metrics of the preceding window
     * @param list<RankedKeyword> $keywords tracked keywords with their latest check
     * @param list<RankingMove> $moves position changes against the comparison check
     * @param list<array{competitor: string, comparison: KeywordComparison}> $gaps competitor gaps on the latest check
     * @param list<array{type: string, label: string, severity: string, advice: string, pages: int, examples: list<string>, effort: int}> $siteIssues issue types of the latest completed site audit
     * @param string|null $domain the project's domain without www.
     * @param list<ResearchKeyword> $researchRanked keywords the domain ranks for (keyword research)
     * @param list<array{competitor: string, keyword: ResearchKeyword}> $researchGaps keywords a competitor ranks for and the project does not (position and URL are the competitor's)
     * @param list<BacklinkProfile> $backlinkProfiles link profiles of the project and its competitors
     * @param list<ReferringDomain> $linkGap domains linking to competitors but not to the project
     * @param list<ReferringDomain> $lostLinks referring domains lost in the last 30 days
     * @param list<array{keyword: string, position: ?int, cited: bool, domains: list<string>}> $aiOverviews tracked keywords that showed an AI Overview on the latest check
     * @param list<AiMention> $aiMentions mentions in AI assistants of the project and its competitors
     * @param list<array{keyword: string, position: ?int, top: list<LocalResult>}> $localRankings Google Maps positions on the latest local check
     * @param list<\SeoControlCenter\Domain\WebVitals\WebVitalRecord> $webVitals mobile field data
     * @param array<string, int> $keywordRatings normalized keyword => business potential 0-3 set by the team
     * @param array<string, SerpLandscape> $serps normalized tracked keyword => its latest stored results page
     * @param array<string, int> $domainStrengths bare domain => strength 0-100 (on-request check of the result domains)
     * @param list<ResearchKeyword> $researchIdeas keyword ideas lookups
     * @param list<ResearchKeyword> $researchSuggestions keyword suggestions lookups (seeds expanded with modifiers)
     * @param array<string, true> $aiOverviewsEarlier normalized tracked keywords that showed an AI Overview on the earlier check
     * @param list<array{prompt: string, platform: string, brand_mentioned: bool, own_cited: bool, competitors: list<array{domain: string, mentioned: bool, cited: bool}>}> $aiAnswers sampled AI answers to the team's prompts
     * @param list<string> $brandNames the project's own brand words (normalized)
     * @param array<value-of<\SeoControlCenter\Domain\SearchConsole\SearchType>, array{now: Metrics, previous: Metrics}> $surfaces other Google surfaces, current and preceding window
     * @param list<\SeoControlCenter\Domain\SearchConsole\Sitemap> $sitemaps the property's sitemaps as Google last processed them
     * @param list<array{date: string, inIndex: int, crawled: int, errors: int, code4xx: int, code5xx: int, blocked: int}> $bingCrawl Bing's daily crawl counts, oldest first
     * @param array<string, string> $pageUpdates page URL => the day the sitemap says it was last modified (Y-m-d)
     * @param list<array{label: string, owner: string, manual: bool, share: ?float, pages: list<array{url: string, clicks: int, impressions: int}>}> $topics Topics & owners: each topic's owner page (set by a person, or inferred with its share of the topic) and the other pages shown for it (0.42.0)
     */
    public function __construct(
        public ?string $searchConsoleWindow = null,
        public array $queries = [],
        public array $pages = [],
        public array $previousPages = [],
        public ?string $rankingDate = null,
        public ?string $compareDate = null,
        public array $keywords = [],
        public array $moves = [],
        public array $gaps = [],
        public ?string $siteAuditDate = null,
        public array $siteIssues = [],
        public ?string $domain = null,
        public ?string $researchDate = null,
        public array $researchRanked = [],
        public array $researchGaps = [],
        public ?string $backlinksDate = null,
        public array $backlinkProfiles = [],
        public array $linkGap = [],
        public array $lostLinks = [],
        public int $competitorCount = 0,
        public ?string $aiOverviewDate = null,
        public array $aiOverviews = [],
        public array $aiMentions = [],
        public ?string $localDate = null,
        public ?string $businessName = null,
        public array $localRankings = [],
        public ?BusinessProfile $businessProfile = null,
        public array $webVitals = [],
        public array $coverage = [],
        public ?CtrModel $ctrModel = null,
        public array $searchSources = [],
        public ?array $googlePages = null,
        public array $confidenceObservations = [],
        public ?string $analysisDate = null,
        public array $refreshDays = [],
        public array $landingPages = [],
        public array $queryPages = [],
        public ?string $currency = null,
        public ?array $ga4Totals = null,
        public ?TrendSnapshot $trends = null,
        public ?PanelSnapshot $panel = null,
        public array $outcomeFactors = [],
        public ?BusinessContext $business = null,
        public array $keywordRatings = [],
        public array $serps = [],
        public array $domainStrengths = [],
        public array $researchIdeas = [],
        public array $researchSuggestions = [],
        public array $aiOverviewsEarlier = [],
        public ?string $aiOverviewEarlierDate = null,
        public array $aiAnswers = [],
        public ?string $aiAnswersDate = null,
        public array $brandNames = [],
        public array $surfaces = [],
        public array $sitemaps = [],
        public array $bingCrawl = [],
        /** @var list<\SeoControlCenter\Domain\Comparison\Analysis> latest finished "Why they outrank you" analysis per topic, 30 days */
        public array $comparisons = [],
        public array $pageUpdates = [],
        public array $topics = [],
        /** @var ?\SeoControlCenter\Domain\AiVisibility\AiCrawlerCheck the latest robots.txt check of AI crawlers, when fresh (0.43.0) */
        public ?\SeoControlCenter\Domain\AiVisibility\AiCrawlerCheck $aiCrawlers = null,
        /**
         * @var list<array{key: string, members: list<string>, evidence: list<string>, uncertainty: string, score: float, decision: ?string}> pages of the
         *      latest site audit whose body text is nearly the same and whose purpose looks alike, unless a person chose to keep them separate (0.44.0)
         */
        public array $nearDuplicates = [],
        /** The Business Profile against the latest site audit's business details (0.46.0); null without a profile. */
        public ?NapCheck $napCheck = null,
        /**
         * @var array{items: list<array<string, mixed>>, fetched: ?string}|null the imported Google reviews of the
         *      business (0.46.0); null when none were imported for the current profile
         */
        public ?array $localReviews = null,
    ) {}

    /** @return list<string> the project's brand words, normalized */
    public function brandTerms(): array
    {
        return $this->brandNames;
    }

    /**
     * Research keywords from every fresh lookup (ranking, gaps, ideas and
     * suggestions), in that order of preference.
     *
     * @return list<ResearchKeyword>
     */
    public function researchKeywords(): array
    {
        $rows = [];
        foreach ($this->freshLists() as $list) {
            array_push($rows, ...$list);
        }

        return $rows;
    }

    /**
     * SERP element types reported by keyword research, per source and
     * normalized keyword.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function researchSerpTypes(): array
    {
        $result = [];
        foreach ($this->freshLists() as $source => $rows) {
            foreach ($rows as $row) {
                if ($row->serpTypes !== null) {
                    $result[$source][Keyword::normalize($row->keyword)] ??= $row->serpTypes;
                }
            }
        }

        return $result;
    }

    /** @return array<string, list<ResearchKeyword>> */
    private function freshLists(): array
    {
        $lists = [...$this->freshResearch(), 'research_ideas' => $this->researchIdeas, 'research_suggestions' => $this->researchSuggestions];
        if ($this->coverage === []) {
            return $lists;
        }

        return array_filter($lists, fn(array $rows, string $source): bool => ($this->coverage[$source]['status'] ?? 'missing') === 'available', ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Tracked keywords, normalized: they form a topic even alone (0.19.1),
     * because they matter to the project and have a stored results page.
     *
     * @return array<string, true>
     */
    public function tracked(): array
    {
        $tracked = [];
        foreach ($this->keywords as $row) {
            $tracked[\SeoControlCenter\Domain\Keywords\Keyword::normalize($row->keyword)] = true;
        }
        foreach (array_keys($this->serps) as $keyword) {
            $tracked[(string) $keyword] = true;
        }

        return $tracked;
    }

    public function expectedCtr(?int $position): float
    {
        return $this->ctrModel?->expected($position) ?? \SeoControlCenter\Domain\Rankings\Visibility::ctr($position);
    }

    public function ctrBasis(?int $position): string
    {
        return ($this->ctrModel ?? new CtrModel())->basis($position);
    }

    public function isTracked(string $keyword): bool
    {
        $keyword = Keyword::normalize($keyword);
        foreach ($this->keywords as $k) {
            if ($k->keyword === $keyword) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keyword difficulty (0-100) from keyword research, if known.
     */
    public function difficultyOf(string $keyword): ?int
    {
        $keyword = Keyword::normalize($keyword);
        foreach ($this->freshLists() as $rows) {
            foreach ($rows as $k) {
                if ($k->difficulty !== null && Keyword::normalize($k->keyword) === $keyword) {
                    return $k->difficulty;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public function difficultySourcesOf(string $keyword): array
    {
        $keyword = Keyword::normalize($keyword);
        foreach ($this->freshLists() as $source => $rows) {
            foreach ($rows as $row) {
                if ($row->difficulty !== null && Keyword::normalize($row->keyword) === $keyword) {
                    return [$source];
                }
            }
        }
        return [];
    }

    /** @return array<string, list<\SeoControlCenter\Domain\Research\ResearchKeyword>> */
    private function freshResearch(): array
    {
        $sources = ['research_ranked' => $this->researchRanked, 'research_gap' => array_column($this->researchGaps, 'keyword')];
        if ($this->coverage === []) {
            return $sources;
        }
        return array_filter($sources, fn(array $rows, string $source): bool => ($this->coverage[$source]['status'] ?? 'missing') === 'available', ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Search Console clicks (current window) of a page, matched by path.
     */
    public function clicksOfPath(string $path): int
    {
        foreach ($this->pages as $row) {
            if (rtrim((string) (parse_url($row->key, PHP_URL_PATH) ?: '/'), '/') === rtrim($path, '/')) {
                return (int) $row->metrics->clicks;
            }
        }

        return 0;
    }

    /**
     * Search volume of a tracked keyword by text, if known.
     */
    public function volumeOf(string $keyword): ?int
    {
        foreach ($this->keywords as $k) {
            if ($k->keyword === $keyword) {
                return $k->searchVolume;
            }
        }

        return null;
    }
}
