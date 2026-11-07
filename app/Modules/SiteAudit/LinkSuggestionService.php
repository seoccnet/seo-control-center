<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Clusters\ClusterRepository;
use SeoControlCenter\Domain\Clusters\KeywordCluster;
use SeoControlCenter\Domain\Clusters\PageKey;
use SeoControlCenter\Domain\InternalLinks\LinkClass;
use SeoControlCenter\Domain\InternalLinks\LinkGraphRepository;
use SeoControlCenter\Domain\InternalLinks\LinkSuggester;
use SeoControlCenter\Domain\Opportunities\OpportunityConsolidator;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\SiteAudit\Crawler\UrlNormalizer;

/**
 * The internal link graph of the newest completed audit and the link
 * suggestions built from it (ADR-040, F1). Suggestions are built in the
 * background after an audit completes and once a day, never in a request.
 */
final readonly class LinkSuggestionService
{
    public const int PER_PAGE = 25;
    /** The topic name and its strongest searches are matched in paragraphs. */
    private const int PHRASES = 8;
    /** Address folders whose pages are archives that list posts (0.25.5 behaviour, ADR-040). */
    private const array ARCHIVES = ['tag', 'tags', 'topic', 'topics', 'category', 'categories', 'author', 'authors', 'archive', 'archives'];

    public function __construct(
        private LinkGraphRepository $graph,
        private SiteAuditRepository $audits,
        private ProjectRepository $projects,
        private Clock $clock,
        private ?ClusterRepository $clusters = null,
    ) {}

    /** Builds the suggestions of one completed audit when it is still the project's newest; returns how many. */
    public function build(int $auditId): int
    {
        $audit = $this->audits->byId($auditId);
        if ($audit === null || $audit->status !== 'completed' || $this->audits->latest($audit->projectId, 'completed')?->id !== $audit->id) {
            return 0;
        }
        $project = $this->project($audit->workspaceId, $audit->projectId);
        if ($project === null || $this->clusters === null) {
            return 0;
        }
        $pages = $this->graph->graphPages($audit->id);
        $byKey = [];
        foreach ($pages as $id => $page) {
            $byKey[OpportunityConsolidator::pageKey($page['url'])] ??= $id;
        }
        $dedicated = $this->dedicated($pages);
        $targets = [];
        foreach ($this->clusters->forProject($project, false) as $cluster) {
            $page = $cluster->ownerUrl !== null ? ($byKey[OpportunityConsolidator::pageKey($cluster->ownerUrl)] ?? null) : null;
            if ($page === null || !in_array($cluster->status, [KeywordCluster::OWNED, KeywordCluster::CONTESTED], true)) {
                continue;
            }
            // Pages that also get the topic's clicks or impressions compete for it: never a source for it.
            $exclude = [];
            foreach ($cluster->candidates as $candidate) {
                $id = $byKey[OpportunityConsolidator::pageKey($candidate['url'])] ?? null;
                if ($id !== null && $id !== $page) {
                    $exclude[] = $id;
                }
            }
            $targets[] = [
                'id' => $page,
                'topic' => $cluster->label,
                'cluster_id' => $cluster->publicId,
                'phrases' => array_values(array_unique([$cluster->label, ...array_slice($cluster->keywords(), 0, self::PHRASES)])),
                'demand' => $cluster->demand,
                'exclude' => $exclude,
            ];
        }
        // Paginated copies (?page=2) repeat their first page's text: only the first page is a source.
        $paragraphs = array_values(array_filter($this->graph->paragraphs($audit->id), static fn(array $p): bool => isset($pages[$p['page_id']]) && PageKey::of($pages[$p['page_id']]['url']) === $pages[$p['page_id']]['url']));
        $leftOut = 0;
        $rows = $targets === [] ? [] : LinkSuggester::suggest($targets, $paragraphs, $pages, $this->graph->linked($audit->id), $dedicated, $leftOut);
        $this->graph->replaceSuggestions($audit->id, $audit->projectId, $rows, $this->clock->now()->format('Y-m-d H:i:s'), $leftOut);

        return count($rows);
    }

    /**
     * Where your crawled pages could link to articles that do not exist yet
     * (topic plans, ADR-041 G2): the link suggester on the newest audit's
     * paragraphs, with the planned articles as targets (negative IDs).
     *
     * @param list<array{id: int, topic: string, cluster_id: ?string, phrases: list<string>, demand: float}> $targets
     * @return array{audit: ?string, rows: list<array{source_url: string, target_id: int, anchor: string, paragraph: string}>}
     */
    public function plannedLinks(Project $project, array $targets): array
    {
        $audit = $this->audits->latest($project->id, 'completed');
        if ($audit === null || $targets === [] || !$this->graph->hasLinkText($audit->id)) {
            return ['audit' => null, 'rows' => []];
        }
        $pages = $this->graph->graphPages($audit->id);
        $dedicated = $this->dedicated($pages);
        $paragraphs = array_values(array_filter($this->graph->paragraphs($audit->id), static fn(array $p): bool => isset($pages[$p['page_id']]) && PageKey::of($pages[$p['page_id']]['url']) === $pages[$p['page_id']]['url']));
        foreach ($targets as $target) {
            $pages[$target['id']] = ['url' => '', 'inbound' => 0];
        }
        $rows = [];
        foreach (LinkSuggester::suggest($targets, $paragraphs, $pages, [], $dedicated) as $row) {
            if ($row['source_page_id'] > 0) {
                $rows[] = ['source_url' => $pages[$row['source_page_id']]['url'], 'target_id' => $row['target_page_id'], 'anchor' => $row['anchor'], 'paragraph' => $row['paragraph']];
            }
        }

        return ['audit' => $audit->finishedAt ?? $audit->createdAt, 'rows' => $rows];
    }

    /**
     * The page each address segment names (/seeds/triploid-seeds → "triploid seed"). Tag,
     * category and author archives (/blog/topics/tomato-seeds) only list posts: not the page for a search.
     *
     * @param array<int, array{url: string, inbound: int}> $pages
     * @return array<string, int>
     */
    private function dedicated(array $pages): array
    {
        $dedicated = [];
        foreach ($pages as $id => $page) {
            $path = (string) parse_url($page['url'], PHP_URL_PATH);
            $segment = rawurldecode((string) basename(rtrim($path, '/')));
            $parent = strtolower(basename((string) dirname(rtrim($path, '/'))));
            $key = $segment !== '' && !in_array($parent, self::ARCHIVES, true) ? LinkSuggester::stemKey($segment) : '';
            if (str_contains($key, ' ')) {
                $dedicated[$key] ??= $id;
            }
        }

        return $dedicated;
    }

    /**
     * recorded: the audit kept link places and text (audits crawled before 0.25.0 did not);
     * running: a newer audit is crawling now; built_at: when the suggestions were last built (UTC).
     *
     * @return array{audit: ?SiteAudit, recorded: bool, running: bool, built_at: ?string, suggestions: list<array{source_url: string, target_url: string, cluster_id: ?string, topic: string, anchor: string, paragraph: string, score: float}>, total: int, page: int, pages: int, left_out: ?int, coverage: array{pages_crawled: ?int, max_pages: ?int, crawled_at: ?string, limits: list<string>}}
     */
    public function suggestions(Project $project, string $search, int $page): array
    {
        $audit = $this->audits->latest($project->id, 'completed');
        $running = $this->audits->latest($project->id)?->isActive() ?? false;
        if ($audit === null) {
            return ['audit' => null, 'recorded' => false, 'running' => $running, 'built_at' => null, 'suggestions' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'left_out' => null, 'coverage' => self::coverage(null)];
        }
        if (!$this->graph->hasLinkText($audit->id)) {
            return ['audit' => $audit, 'recorded' => false, 'running' => $running, 'built_at' => null, 'suggestions' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'left_out' => null, 'coverage' => self::coverage($audit)];
        }
        $search = mb_substr(trim($search), 0, 200);
        $total = $this->graph->countSuggestions($project->id, $audit->id, $search);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $page));

        return ['audit' => $audit, 'recorded' => true, 'running' => $running, 'built_at' => $this->graph->builtAt($audit->id), 'suggestions' => $this->graph->suggestions($project->id, $audit->id, $search, ($page - 1) * self::PER_PAGE, self::PER_PAGE), 'total' => $total, 'page' => $page, 'pages' => $pages, 'left_out' => $this->graph->leftOut($audit->id), 'coverage' => self::coverage($audit)];
    }

    /**
     * Links into and out of one crawled page of the newest completed audit.
     *
     * @return array{audit: ?SiteAudit, url: ?string, found: bool, recorded: bool, in: list<array{url: string, anchor: ?string, place: ?string, class: ?string, confidence: ?string, context: ?string}>, out: list<array{url: string, anchor: ?string, place: ?string, class: ?string, confidence: ?string, context: ?string}>, in_by_class?: array<string, array{label: string, count: int, pages: int, links: list<array{url: string, anchor: ?string, place: ?string, class: ?string, confidence: ?string, context: ?string}>}>, out_by_class?: array<string, array{label: string, count: int, pages: int, links: list<array{url: string, anchor: ?string, place: ?string, class: ?string, confidence: ?string, context: ?string}>}>}
     */
    public function pageLinks(Project $project, string $url, int $limit = 200): array
    {
        $audit = $this->audits->latest($project->id, 'completed');
        $normalized = UrlNormalizer::normalize(trim($url));
        $page = $audit !== null && $normalized !== null ? $this->audits->findPage($audit->id, $normalized) : null;
        if ($audit === null || $page === null) {
            return ['audit' => $audit, 'url' => $normalized, 'found' => false, 'recorded' => false, 'in' => [], 'out' => []];
        }

        $in = $this->graph->pageLinks($audit->id, $page->id, 'in', $limit);
        $out = $this->graph->pageLinks($audit->id, $page->id, 'out', $limit);

        return ['audit' => $audit, 'url' => $page->url, 'found' => true, 'recorded' => $this->graph->hasLinkText($audit->id), 'in' => $in, 'out' => $out, 'in_by_class' => LinkClass::summarize($in), 'out_by_class' => LinkClass::summarize($out)];
    }

    /**
     * How far the link evidence reaches (ADR-042): the crawl and the limits
     * the graph and the suggestions are built under. Missing suggestions do
     * not prove that a page is well linked.
     *
     * @return array{pages_crawled: ?int, max_pages: ?int, crawled_at: ?string, limits: list<string>}
     */
    private static function coverage(?SiteAudit $audit): array
    {
        return [
            'pages_crawled' => $audit?->pagesCrawled,
            'max_pages' => $audit?->maxPages,
            'crawled_at' => $audit !== null ? ($audit->finishedAt ?? $audit->createdAt) : null,
            'limits' => [
                'The first ' . number_format(Crawler\HtmlPage::MAX_LINKS) . ' links of each page are read, and for each linked page only its best occurrence (text first, templates last).',
                'Up to ' . Crawler\HtmlPage::MAX_PARAGRAPHS . ' paragraphs of at least 60 characters per page, each cut to ' . Crawler\HtmlPage::PARAGRAPH_LENGTH . ' characters, are searched for topics.',
                'Only owned or contested topics with an owner page in the crawl are targets; at most ' . LinkSuggester::PER_TARGET . ' suggestions per target, ' . LinkSuggester::PER_SOURCE . ' per source page and ' . LinkSuggester::MAX . ' in all.',
                'Link classes come from page regions, class names and the text around a link; a site with unusual markup can be misread, so each class shows its confidence.',
            ],
        ];
    }

    private function project(int $workspaceId, int $projectId): ?Project
    {
        foreach ($this->projects->allInWorkspace($workspaceId) as $project) {
            if ($project->id === $projectId) {
                return $project;
            }
        }

        return null;
    }
}
