<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\AiVisibility\CitationReadiness;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Opportunities\Rules\ContentRefreshRule;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * The AI visibility page of a project: how often Google shows an AI
 * Overview for its tracked keywords, how often the project is cited there,
 * and which domains (competitors among them) are cited instead.
 */
final readonly class AiVisibilityService
{
    public const int PER_PAGE = 50;
    private const int TOP_DOMAINS = 15;

    public const int CITATION_PAGES = 10;

    public function __construct(
        private AiOverviewRepository $overviews,
        private RankingRepository $rankings,
        private CompetitorRepository $competitors,
        private ?SiteAuditRepository $siteAudits = null,
        private ?SearchPerformanceReader $searchConsole = null,
        private ?Clock $clock = null,
    ) {}

    /**
     * Citation readiness (0.43.0) of the site's top Search Console pages of
     * the last 28 days, from what the latest completed site audit read on
     * each of them. Pages the audit did not crawl are listed without checks.
     *
     * @return array{auditDate: ?string, pages: list<array{url: string, clicks: int, crawled: bool, article: bool, checks: list<array{key: string, label: string, tested: string, hint: string, passed: ?bool}>, score: array{passed: int, known: int, unknown: int}}>}
     */
    public function citationPages(Project $project): array
    {
        if ($this->siteAudits === null || $this->searchConsole === null || $this->clock === null) {
            return ['auditDate' => null, 'pages' => []];
        }
        $audit = $this->siteAudits->latest($project->id, 'completed');
        $range = DateRange::endingOn($this->clock->now()->modify('-1 day')->format('Y-m-d'), 28);
        $pages = [];
        foreach ($this->searchConsole->top($project->id, PerformanceDimension::Page, $range, self::CITATION_PAGES) as $row) {
            $page = null;
            if ($audit !== null) {
                $page = $this->siteAudits->findPage($audit->id, $row->key);
                // Search Console and the crawler may differ in the trailing slash.
                $page ??= $this->siteAudits->findPage($audit->id, str_ends_with($row->key, '/') ? rtrim($row->key, '/') : $row->key . '/');
            }
            $crawled = $page !== null && $page->isHtml();
            // Articles by markup (Article structured data, og:type article) or by address (a blog or guide folder).
            $article = $crawled && ($page->isArticle() || ContentRefreshRule::article($row->key));
            $checks = $crawled ? CitationReadiness::checks($page->hasAuthor, $page->schemaTypes, $page->sourceLinks, $page->citation, $article) : [];
            $pages[] = ['url' => $row->key, 'clicks' => $row->metrics->clicks, 'crawled' => $crawled, 'article' => $article, 'checks' => $checks, 'score' => CitationReadiness::score($checks, $article)];
        }

        return ['auditDate' => $audit?->finishedAt !== null ? substr($audit->finishedAt, 0, 10) : null, 'pages' => $pages];
    }

    /**
     * @return array{
     *     device: SerpDevice,
     *     date: ?string,
     *     summary: array{keywords: int, present: int, cited: int},
     *     previousDate: ?string,
     *     previous: ?array{keywords: int, present: int, cited: int},
     *     keywords: list<AiOverviewKeyword>,
     *     total: int,
     *     pages: int,
     *     topDomains: list<array{domain: string, keywords: int, own: bool, competitor: bool}>,
     *     competitorDomains: list<string>,
     *     ownDomain: string
     * }
     */
    public function page(Project $project, bool $onlyWithOverview, int $page): array
    {
        $device = $this->rankings->settings($project->id)->device;
        $date = $this->overviews->latestDate($project->id, $device);
        $ownDomain = self::bareDomain($project->primaryDomain);
        $competitorDomains = array_map(static fn(Competitor $c): string => $c->domain, $this->competitors->forProject($project->id));

        $summary = ['keywords' => 0, 'present' => 0, 'cited' => 0];
        $previousDate = null;
        $previous = null;
        $keywords = [];
        $total = 0;
        if ($date !== null) {
            $summary = $this->overviews->summary($project->id, $device, $date);
            $previousDate = $this->overviews->dateOnOrBefore($project->id, $device, (new \DateTimeImmutable($date))->modify('-7 days')->format('Y-m-d'));
            $previous = $previousDate !== null ? $this->overviews->summary($project->id, $device, $previousDate) : null;
            $total = $this->overviews->countKeywords($project->id, $device, $date, $onlyWithOverview);
            $keywords = $this->overviews->keywords($project->id, $device, $date, $onlyWithOverview, self::PER_PAGE, (max(1, $page) - 1) * self::PER_PAGE);
        }

        $topDomains = array_map(static fn(array $row): array => [
            'domain' => $row['domain'],
            'keywords' => $row['keywords'],
            'own' => Competitor::owns($ownDomain, $row['domain']),
            'competitor' => array_filter($competitorDomains, static fn(string $d): bool => Competitor::owns($d, $row['domain'])) !== [],
        ], $this->overviews->topDomains($project->id, $device, self::TOP_DOMAINS));

        return [
            'device' => $device,
            'date' => $date,
            'summary' => $summary,
            'previousDate' => $previousDate,
            'previous' => $previous,
            'keywords' => $keywords,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'topDomains' => $topDomains,
            'competitorDomains' => $competitorDomains,
            'ownDomain' => $ownDomain,
        ];
    }

    public static function bareDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }
}
