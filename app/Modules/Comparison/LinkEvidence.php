<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Comparison;

use SeoControlCenter\Domain\Clusters\KeywordCluster;
use SeoControlCenter\Domain\Comparison\LinkPlanner;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Comparison\PageTypeClassifier;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Pages\PageInsights;
use SeoControlCenter\Modules\Pages\PageRepository;
use SeoControlCenter\Modules\Pages\PageService;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * Collects the stored evidence for the internal-link plan of an analysis
 * (ADR-038, D2): the topic's pages with their types, the latest complete
 * site audit's link graph, Search Console queries per page and the
 * related pages Pages already suggests as link sources.
 */
final readonly class LinkEvidence
{
    public function __construct(
        private SiteAuditRepository $audits,
        private PageRepository $pages,
        private PageService $pageService,
        private PageInsights $insights,
    ) {}

    /**
     * @param array<string, PageStructure> $fetched
     * @param array{type: string, confidence: string, signals: list<string>}|null $ownType
     * @param list<string> $keywords
     * @return array{plan: array<string, mixed>, types: array<string, string>}
     */
    public function plan(Project $project, ?KeywordCluster $cluster, ?string $ownUrl, ?array $ownType, array $fetched, array $keywords): array
    {
        $audit = $this->audits->latest($project->id, 'completed');
        $crawled = $audit !== null ? $this->audits->allPages($audit->id) : [];
        $byUrl = [];
        foreach ($crawled as $page) {
            $byUrl[$page->url] = $page;
        }
        $rows = [];
        if ($ownUrl !== null) {
            $rows[$ownUrl] = ['url' => $ownUrl, 'clicks' => 0, 'impressions' => 0];
        }
        foreach ($cluster !== null ? $cluster->candidates : [] as $c) {
            $rows[$c['url']] = ['url' => $c['url'], 'clicks' => $c['clicks'], 'impressions' => $c['impressions']];
        }
        $topicPages = [];
        $types = [];
        foreach ($rows as $url => $row) {
            $type = $url === $ownUrl && $ownType !== null ? $ownType['type'] : PageTypeClassifier::classify($url, $byUrl[$url]->title ?? null, $fetched[$url] ?? null)['type'];
            $topicPages[] = $row + ['type' => $type];
            $types[$url] = $type;
        }

        $queries = [];
        $clicks = [];
        foreach ($this->pages->queries($project->id, $this->pageService->range($project)) as $q) {
            $clicks[$q->url] = ($clicks[$q->url] ?? 0) + $q->metrics->clicks;
            if (isset($rows[$q->url])) {
                $queries[$q->url][] = ['query' => $q->query, 'impressions' => $q->metrics->impressions];
            }
        }
        $competing = [];
        foreach ($cluster !== null ? $cluster->overlap : [] as $o) {
            $urls = is_array($o['pages'] ?? null) ? array_column($o['pages'], 'url') : [];
            if (($o['kind'] ?? null) === 'switching' && count($urls) >= 2 && is_string($urls[0]) && is_string($urls[1]) && !in_array([$urls[0], $urls[1]], $competing, true)) {
                $competing[] = [$urls[0], $urls[1]];
            }
        }
        $outlines = [];
        foreach ($fetched as $url => $page) {
            if (isset($rows[$url])) {
                $outlines[$url] = array_map(static fn(array $h): string => $h['text'], $page->outline);
            }
        }
        if ($audit === null) {
            return ['plan' => LinkPlanner::plan($topicPages, null, [], [], $queries, $competing, $outlines, $keywords, $ownUrl), 'types' => $types];
        }

        $inbound = $this->audits->inboundCounts($audit->id);
        $graph = [];
        $linkedFrom = [];
        foreach ($crawled as $page) {
            if (self::linkable($page)) {
                $graph[$page->url] = ['title' => $page->title, 'noindex' => $page->noindex, 'inbound' => $inbound[$page->id] ?? 0];
            }
        }
        foreach (array_keys($rows) as $url) {
            if (isset($byUrl[$url])) {
                $linkedFrom[$url] = $this->audits->linkSources($audit->id, $byUrl[$url]->id, 2000);
            }
        }
        $roles = LinkPlanner::roles($topicPages);
        $main = array_search('money', $roles, true);
        $main = is_string($main) ? $main : $ownUrl;
        $donors = [];
        if ($main !== null && isset($byUrl[$main])) {
            foreach ($this->insights->internalLinks($byUrl[$main], $crawled, $inbound, $linkedFrom[$main] ?? [], $keywords) as $d) {
                $donors[] = ['url' => $d['url'], 'clicks' => $clicks[$d['url']] ?? 0, 'terms' => $d['matching_terms']];
            }
            usort($donors, static fn(array $a, array $b): int => [$b['clicks'], $a['url']] <=> [$a['clicks'], $b['url']]);
        }
        foreach ($topicPages as $i => $page) {
            $topicPages[$i]['clicks'] = max($page['clicks'], $clicks[$page['url']] ?? 0);
        }

        return ['plan' => LinkPlanner::plan($topicPages, $graph, $linkedFrom, array_slice($donors, 0, 5), $queries, $competing, $outlines, $keywords, $ownUrl), 'types' => $types];
    }

    private static function linkable(AuditPage $page): bool
    {
        return $page->isHtml() && ($page->canonical === null || $page->canonical === '' || $page->canonical === $page->url);
    }
}
