<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ArticleIdeas;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRepository;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRun;
use SeoControlCenter\Domain\ArticleIdeas\ArticleOutline;
use SeoControlCenter\Domain\ArticleIdeas\IdeaBuilder;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Keywords\Markets;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Opportunities\BusinessContextRepository;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Relevance;
use SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\Research\ResearchProvider;

/**
 * Article ideas (ADR-041, G1): a paid, confirmed run buys competitors'
 * ranked keywords once; ideas are built from the stored rows for free after
 * the run, daily and on request, against your current rankings, topics and
 * relevance rules. Provider calls happen in queued jobs only.
 *
 * Markets: a run covers every market the project targets (marketPlan()):
 * the business profile's markets, else the markets of its tracked keywords
 * by keyword count, else the default market. It buys one lookup per
 * competitor and market; markets DataForSEO Labs has no data for are
 * skipped and named, never paid for.
 */
final readonly class ArticleIdeaService
{
    public const int MAX_COMPETITORS = 5;
    public const int ROWS_PER_COMPETITOR = 500;
    public const int SHOWN = 50;
    /** Where the markets of a run come from (marketPlan()). */
    public const string MARKETS_PROFILE = 'profile';
    public const string MARKETS_KEYWORDS = 'keywords';
    public const string MARKETS_DEFAULT = 'default';
    public const int REPEAT_DAYS = 30;
    /** Free rebuilds a project may request per hour. */
    public const int REBUILDS_PER_HOUR = 6;
    /** Competitor articles read per outline, and outline reads a project may request per hour. */
    public const int OUTLINE_PAGES = 3;
    public const int OUTLINES_PER_HOUR = 20;
    /** Sites that rank for almost everything; they say little about your competition. */
    private const array GENERIC = ['wikipedia.org', 'youtube.com', 'reddit.com', 'quora.com', 'facebook.com', 'instagram.com', 'pinterest.com', 'tiktok.com', 'x.com', 'twitter.com', 'linkedin.com', 'amazon.com', 'ebay.com'];

    public function __construct(
        private ArticleIdeaRepository $ideas,
        private ArticleIdeaScheduler $scheduler,
        private ResearchProvider $provider,
        private CompetitorRepository $competitors,
        private SerpLandscapeRepository $serps,
        private KeywordRepository $keywords,
        private ProjectRepository $projects,
        private \Closure $signals,
        private Authorizer $auth,
        private FeatureManager $features,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private Clock $clock,
        /** @var (\Closure(string): PageStructure)|null reads one page's structure (the free page reader) */
        private ?\Closure $reader = null,
        private ?\SeoControlCenter\Domain\ArticleIdeas\TopicPlanRepository $plans = null,
        /** @var (\Closure(Project, string): list<string>)|null a topic plan's links of one idea, as brief lines (G2) */
        private ?\Closure $planLinks = null,
        /** @var (\Closure(Project): array<string, array{low: int, high: int, count: int}>)|null the site's own time to the top 10 (G3) */
        private ?\Closure $calibration = null,
        /** @var (\Closure(Project): list<\SeoControlCenter\Domain\ArticleIdeas\ArticleTracking>)|null published articles from ideas (G3) */
        private ?\Closure $published = null,
        /** @var (\Closure(Project, list<string>): array<string, list<array{url: ?string, title: ?string, state: string, state_label: string, sources: list<string>, article: bool}>>)|null content-inventory rows on each topic (ADR-042) */
        private ?\Closure $inventory = null,
        private ?BusinessContextRepository $business = null,
    ) {}

    /**
     * The markets a run covers, in priority order: the business profile's
     * markets; without them the distinct markets of the tracked keywords,
     * most keywords first; without keywords the default market. At most
     * BusinessContext::MAX_MARKETS. Markets DataForSEO Labs has no data for
     * are moved to $skipped with the reason.
     *
     * @return array{markets: list<KeywordLocale>, source: string, skipped: list<array{market: string, label: string, reason: string}>}
     */
    public function marketPlan(Project $project): array
    {
        $chosen = [];
        foreach ($this->business?->find($project->id)->markets ?? [] as $key) {
            $locale = KeywordLocale::fromKey($key);
            if ($locale !== null) {
                $chosen[$locale->key()] = $locale;
            }
        }
        $source = self::MARKETS_PROFILE;
        if ($chosen === []) {
            $source = self::MARKETS_KEYWORDS;
            foreach ($this->keywords->marketsByCount($project->id, BusinessContext::MAX_MARKETS) as $locale) {
                $chosen[$locale->key()] = $locale;
            }
        }
        if ($chosen === []) {
            $source = self::MARKETS_DEFAULT;
            $chosen[KeywordLocale::default()->key()] = KeywordLocale::default();
        }
        $markets = [];
        $skipped = [];
        foreach (array_slice(array_values($chosen), 0, BusinessContext::MAX_MARKETS) as $locale) {
            $problem = Markets::labsProblem($locale->locationCode, $locale->languageCode);
            if ($problem === null) {
                $markets[] = $locale;
            } else {
                $skipped[] = ['market' => $locale->key(), 'label' => $locale->label(), 'reason' => $problem];
            }
        }

        return ['markets' => $markets, 'source' => $source, 'skipped' => $skipped];
    }

    /** What one lookup (one competitor in one market) costs at the provider's current price. */
    public function lookupCost(): float
    {
        return $this->provider->estimatedCost(self::ROWS_PER_COMPETITOR, 1);
    }

    /**
     * @return array{ideas: list<ArticleIdea>, built_at: ?string, run: ?ArticleIdeaRun, competitors: list<array{domain: string, found: bool}>, estimated_cost: float, lookup_cost: float, market: string, markets: list<array{key: string, label: string, cost: float}>, market_source: string, skipped_markets: list<array{market: string, label: string, reason: string}>, idea_markets: list<array{key: string, label: string}>, market_filter: ?string, can_edit: bool, featureOn: bool, available: bool, rebuildable: bool, outlines: array<string, ArticleOutline>, outline_fresh_since: string, plans: array<string, string>, published: list<\SeoControlCenter\Domain\ArticleIdeas\ArticleTracking>, today: string, inventory: array<string, list<array{url: ?string, title: ?string, state: string, state_label: string, sources: list<string>, article: bool}>>}
     */
    public function overview(User $user, Project $project, ?string $marketFilter = null): array
    {
        $competitors = $this->plannedCompetitors($project);
        $plan = $this->marketPlan($project);
        $lookup = $this->lookupCost();
        $perMarket = $lookup * max(1, count($competitors));
        $done = $this->latestDone($project);
        // Ideas built before markets were stored belong to their run's market.
        $fallback = $done?->marketKeys()[0] ?? ($plan['markets'][0] ?? KeywordLocale::default())->key();
        $all = array_map(static fn(ArticleIdea $i): ArticleIdea => $i->inMarket($fallback), $this->ideas->ideas($project, self::SHOWN));
        $found = [];
        foreach ($all as $idea) {
            foreach ($idea->marketKeys() as $key) {
                $found[$key] = true;
            }
        }
        $order = array_flip([...($done?->marketKeys() ?? []), ...array_map(static fn(KeywordLocale $l): string => $l->key(), $plan['markets'])]);
        $found = array_keys($found);
        usort($found, static fn(string $a, string $b): int => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);
        $filter = $marketFilter !== null && in_array($marketFilter, $found, true) ? $marketFilter : null;
        $ideas = $filter === null ? $all : array_values(array_filter($all, static fn(ArticleIdea $i): bool => in_array($filter, $i->marketKeys(), true)));

        return [
            'ideas' => $ideas,
            'built_at' => $this->ideas->builtAt($project->id),
            'run' => $this->ideas->latestRun($project),
            'competitors' => $competitors,
            // One lookup per competitor and market, at the current price per lookup.
            'estimated_cost' => $perMarket * max(1, count($plan['markets'])),
            'lookup_cost' => $lookup,
            'market' => implode(', ', array_map(static fn(KeywordLocale $l): string => $l->label(), $plan['markets'])),
            'markets' => array_map(static fn(KeywordLocale $l): array => ['key' => $l->key(), 'label' => $l->label(), 'cost' => $perMarket], $plan['markets']),
            'market_source' => $plan['source'],
            'skipped_markets' => $plan['skipped'],
            'idea_markets' => array_map(static fn(string $key): array => ['key' => $key, 'label' => ArticleIdea::label($key)], $found),
            'market_filter' => $filter,
            'can_edit' => $this->auth->can($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id),
            'featureOn' => $this->features->enabled(Feature::KeywordMetrics),
            'available' => $this->provider->isAvailable($project->workspaceId),
            'rebuildable' => $this->latestDone($project) !== null,
            'outlines' => $this->ideas->outlines($project, array_map(static fn(ArticleIdea $i): string => $i->key, $ideas)),
            'outline_fresh_since' => $this->clock->now()->modify('-' . ArticleOutline::FRESH_DAYS . ' days')->format('Y-m-d H:i:s'),
            // Ideas that are the pillar of a topic plan => the plan's public ID.
            'published' => $this->published !== null ? ($this->published)($project) : [],
            'today' => $this->clock->now()->format('Y-m-d'),
            'plans' => array_column(array_map(static fn(\SeoControlCenter\Domain\ArticleIdeas\TopicPlan $p): array => [$p->pillarKey, $p->publicId], $this->plans?->forProject($project, 200) ?? []), 1, 0),
            // New-article ideas checked against the content inventory: a draft or page without rankings is not "missing" (ADR-042).
            'inventory' => $this->covered($project, $ideas),
        ];
    }

    /**
     * New-article ideas checked against the content inventory in one join.
     *
     * @param list<ArticleIdea> $ideas
     * @return array<string, list<array{url: ?string, title: ?string, state: string, state_label: string, sources: list<string>, article: bool}>> per idea key, ideas with matches only
     */
    private function covered(Project $project, array $ideas): array
    {
        $new = array_values(array_filter($ideas, static fn(ArticleIdea $i): bool => $i->kind === ArticleIdea::NEW));
        if ($this->inventory === null || $new === []) {
            return [];
        }
        $byTopic = ($this->inventory)($project, array_map(static fn(ArticleIdea $i): string => $i->mainKeyword, $new));
        $found = [];
        foreach ($new as $idea) {
            if (($byTopic[$idea->mainKeyword] ?? []) !== []) {
                $found[$idea->key] = $byTopic[$idea->mainKeyword];
            }
        }

        return $found;
    }

    /** @return list<ArticleIdea> */
    public function top(Project $project, int $limit): array
    {
        return $this->ideas->ideas($project, $limit);
    }

    /**
     * Starts a paid run; $confirmPaid is required, $confirmRepeat when a run
     * finished within 30 days. $confirmedCost is the total the person saw and
     * confirmed: a run that would now cost more is refused, so the run never
     * spends more than was confirmed.
     */
    public function start(User $user, Project $project, bool $confirmPaid, bool $confirmRepeat, ClientInfo $client, ?float $confirmedCost = null): ArticleIdeaRun
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->features->require(Feature::KeywordMetrics);
        if (!$this->provider->isAvailable($project->workspaceId)) {
            throw new HttpException(409, 'Connect DataForSEO under Integrations first.');
        }
        $competitors = $this->plannedCompetitors($project);
        if ($competitors === []) {
            throw new HttpException(409, 'Add a competitor under Research → Competitors, or track keywords so SEOcc can find the sites that rank for them.');
        }
        $plan = $this->marketPlan($project);
        if ($plan['markets'] === []) {
            throw new HttpException(409, sprintf('DataForSEO Labs has no competitor rankings for %s. Add a market it covers to the business profile.', implode(', ', array_column($plan['skipped'], 'label'))));
        }
        $markets = $plan['markets'];
        $cost = $this->lookupCost() * count($competitors) * count($markets);
        $breakdown = count($markets) > 1 ? sprintf(' for %d competitor%s in %d markets', count($competitors), count($competitors) === 1 ? '' : 's', count($markets)) : '';
        if (!$confirmPaid) {
            throw new HttpException(422, sprintf('Finding article ideas buys competitor rankings from DataForSEO (about $%.2f%s). Confirm the paid run.', $cost, $breakdown));
        }
        if ($confirmedCost !== null && round($cost, 2) > round($confirmedCost, 2) + 0.001) {
            throw new HttpException(422, sprintf('The lookup now costs about $%.2f%s, more than the $%.2f you confirmed (competitors or markets changed). Check the page and confirm again.', $cost, $breakdown, $confirmedCost));
        }
        if ($this->ideas->hasActiveRun($project->id)) {
            throw new HttpException(409, 'Article ideas are already being looked up for this project.');
        }
        $previous = $this->ideas->latestDoneRun($project->id);
        if ($previous !== null && !$confirmRepeat && $previous->createdAt >= $this->clock->now()->modify('-' . self::REPEAT_DAYS . ' days')->format('Y-m-d H:i:s')) {
            throw new HttpException(422, sprintf('Article ideas were looked up on %s. Competitor rankings change slowly; tick “Run again and pay” to buy them again.', substr($previous->createdAt, 0, 10)));
        }
        if (!$this->limiter->hit('article_ideas:project:' . $project->id, 3, 3600)->allowed) {
            throw new HttpException(429, 'Wait an hour before looking up article ideas again.');
        }
        $keys = array_map(static fn(KeywordLocale $l): string => $l->key(), $markets);
        $run = $this->ideas->createRun($project, $competitors, $keys[0], $cost, $user->id, $this->now(), $keys);
        $this->scheduler->queue($run->id, $project->workspaceId);
        $this->audit->record(new AuditEvent('article_ideas.run_requested', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['competitors' => array_column($competitors, 'domain'), 'markets' => $keys, 'skipped_markets' => array_column($plan['skipped'], 'market'), 'estimated_cost' => $cost]), $client);

        return $run;
    }

    /** Queues a free rebuild of the project's ideas from its newest finished lookup. */
    public function requestRebuild(User $user, Project $project, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->features->require(Feature::KeywordMetrics);
        if ($this->latestDone($project) === null) {
            throw new HttpException(409, 'There is no finished lookup to rebuild from yet. Find article ideas first.');
        }
        if (!$this->limiter->hit('article_ideas:rebuild:project:' . $project->id, self::REBUILDS_PER_HOUR, 3600)->allowed) {
            throw new HttpException(429, 'Ideas were rebuilt several times this hour. Wait a while before rebuilding again.');
        }
        $now = $this->clock->now();
        // One rebuild per project and ten minutes; a double click returns the queued one.
        $this->scheduler->rebuild($project->id, $project->workspaceId, $now->format('YmdH') . intdiv((int) $now->format('i'), 10));
        $this->audit->record(new AuditEvent('article_ideas.rebuild_requested', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    /** Queues a free read of the idea's competitor outlines (and your page for an update). */
    public function requestOutline(User $user, Project $project, string $key, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->features->require(Feature::KeywordMetrics);
        $idea = $this->ideas->idea($project, $key) ?? throw new NotFoundHttpException();
        if ($this->reader === null) {
            throw new HttpException(409, 'Reading competitor pages is not available on this installation.');
        }
        if (!$this->limiter->hit('article_ideas:outline:project:' . $project->id, self::OUTLINES_PER_HOUR, 3600)->allowed) {
            throw new HttpException(429, 'Many outlines were read this hour. Wait a while before reading more.');
        }
        $now = $this->clock->now();
        if (!$this->ideas->requestOutline($project, $key, $user->id, $now->format('Y-m-d H:i:s'), $now->modify('-' . ArticleOutline::FRESH_DAYS . ' days')->format('Y-m-d H:i:s'), $now->modify('-2 hours')->format('Y-m-d H:i:s'))) {
            $current = $this->ideas->outline($project, $key);
            throw new HttpException(409, $current?->status === ArticleOutline::QUEUED
                ? 'The competitor outlines of this idea are already being read.'
                : sprintf('The competitor outlines of this idea were read on %s; they can be read again a week later.', substr((string) $current?->fetchedAt, 0, 10)));
        }
        $this->scheduler->queueOutline($project->id, $project->workspaceId, $key);
        $this->audit->record(new AuditEvent('article_ideas.outline_requested', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['keyword' => $idea->mainKeyword]), $client);
    }

    /**
     * Reads the outlines of a queued idea: its top competitor articles (one
     * per article, language copies skipped) and, for an update, your page.
     *
     * @param \Closure(): bool $renew extends the job's lease between pages; false stops
     */
    public function readOutline(int $projectId, int $workspaceId, string $key, \Closure $renew): void
    {
        $project = $this->project($workspaceId, $projectId);
        $outline = $project !== null ? $this->ideas->outline($project, $key) : null;
        if ($project === null || $outline === null || $outline->status !== ArticleOutline::QUEUED) {
            return;
        }
        $idea = $this->ideas->idea($project, $key);
        if ($idea === null || $this->reader === null) {
            $this->ideas->failOutline($projectId, $key, $idea === null ? 'The idea is no longer listed. Rebuild or look up the ideas again.' : 'Reading competitor pages is not available on this installation.', $this->now());

            return;
        }
        $urls = [];
        foreach ($idea->competitors as $competitor) {
            $urls[IdeaBuilder::articleKey($competitor['url'])] ??= ['url' => $competitor['url'], 'domain' => $competitor['domain']];
        }
        // One article per site first, so subtopics can be compared across sites; then the next best.
        $first = $rest = [];
        foreach ($urls as $entry) {
            isset($first[$entry['domain']]) ? $rest[] = $entry['url'] : $first[$entry['domain']] = $entry['url'];
        }
        $pages = [];
        foreach (array_slice([...array_values($first), ...$rest], 0, self::OUTLINE_PAGES) as $url) {
            $pages[] = ($this->reader)($url);
            if (!$renew()) {
                return;
            }
        }
        $own = $idea->kind === ArticleIdea::UPDATE && $idea->own !== null ? ($this->reader)($idea->own['url']) : null;
        if (array_filter($pages, static fn(PageStructure $p): bool => $p->readable()) === []) {
            $reasons = array_values(array_unique(array_filter(array_map(static fn(PageStructure $p): ?string => $p->reason, $pages))));
            $this->ideas->failOutline($projectId, $key, 'No competitor article could be read' . ($reasons !== [] ? ': ' . implode(' ', array_slice($reasons, 0, 2)) : '.'), $this->now());

            return;
        }
        $this->ideas->saveOutline($projectId, $key, $pages, $own, $this->now());
    }

    /**
     * What a new content brief starts from for this idea (ADR-041, G1 part 2):
     * target and related searches, an outline from the competitor subtopics,
     * question topics, your page for an update, and the evidence behind it.
     *
     * @return array{keyword: string, url: string, sections: array<string, string>, evidence: list<string>}|null
     */
    public function briefSeed(Project $project, string $key): ?array
    {
        $idea = $this->ideas->idea($project, $key);
        if ($idea === null) {
            return null;
        }
        $outline = $this->ideas->outline($project, $key);
        $subtopics = $outline?->status === ArticleOutline::DONE ? $outline->subtopics() : [];
        $questions = $outline?->status === ArticleOutline::DONE ? $outline->questions($idea->mainKeyword) : [];
        $p = $idea->prediction;
        $lines = ['H1: ' . $idea->mainKeyword];
        foreach (array_slice($subtopics, 0, 12) as $subtopic) {
            $lines[] = 'H2: ' . $subtopic['topic'];
        }
        if ($subtopics === []) {
            $lines[] = 'H2: Read the competitor outlines on the Article ideas page to fill in the subtopics';
        }
        $topics = ['Answer ' . $idea->mainKeyword . ' fully, so the article can win the related searches too.'];
        foreach ($questions as $question) {
            $topics[] = 'Answer: ' . $question;
        }
        foreach ($subtopics as $subtopic) {
            if ($idea->kind === ArticleIdea::UPDATE && !$subtopic['own']) {
                $topics[] = 'Missing on your page: ' . $subtopic['topic'];
            }
        }
        $evidence = [
            'Article idea: ' . ($idea->kind === ArticleIdea::UPDATE ? 'update your article' : 'new article') . ' for ' . $idea->mainKeyword . ' | ' . count($idea->keywords) . ' searches, ' . number_format($idea->volume) . ' a month',
            ...($idea->markets !== [] ? [(count($idea->markets) > 1 ? 'Markets: ' : 'Market: ') . implode(', ', array_map(static fn(array $m): string => ArticleIdea::label($m['market']) . (count($idea->markets) > 1 ? ' (' . number_format($m['volume']) . ' a month)' : ''), $idea->markets))] : []),
            sprintf('Estimates: %s–%s clicks a month (about %s at position 5) | difficulty %s | %s | %d–%d months to rank (assumption)', number_format($p['traffic']['low']), number_format($p['traffic']['high']), number_format($p['traffic']['expected']), $p['difficulty']['level'], $p['backlinks'] === null ? 'backlinks unknown' : $p['backlinks']['low'] . '–' . $p['backlinks']['high'] . ' referring domains to speed it up', $p['months']['low'], $p['months']['high']),
        ];
        foreach ($idea->competitors as $competitor) {
            $evidence[] = 'Competitor article: ' . $competitor['url'] . ' | best position ' . ($competitor['position'] ?? 'unknown') . ' | ' . $competitor['keywords'] . ($competitor['keywords'] === 1 ? ' search' : ' searches');
        }
        if ($outline?->status === ArticleOutline::DONE) {
            $evidence[] = sprintf('Competitor outlines read %s UTC: %d of %d articles readable, from %d site%s; subtopics two or more sites cover become the H2s (headings a site repeats on its articles are left out as template).', $outline->fetchedAt, count($outline->readable()), count($outline->pages), count($outline->sites()), count($outline->sites()) === 1 ? '' : 's');
        }
        foreach ($idea->reasons as $reason) {
            $evidence[] = $reason;
        }

        $links = $this->planLinks !== null ? ($this->planLinks)($project, $key) : [];
        if ($links !== []) {
            $evidence[] = 'Internal links from the topic plan this idea belongs to (built from your newest site audit).';
        }

        return [
            'keyword' => $idea->mainKeyword,
            'url' => $idea->kind === ArticleIdea::UPDATE && $idea->own !== null ? $idea->own['url'] : '',
            'sections' => [...($links !== [] ? ['internal_links' => implode("\n", $links)] : []),
                'intent' => 'Informational: searchers want an article or guide (the competitor pages that win are articles). Describe the audience and the next step you want them to take.',
                'related_queries' => implode("\n", array_column(array_slice($idea->keywords, 1), 'keyword')),
                'outline' => implode("\n", $lines),
                'topics' => implode("\n", $topics),
            ],
            'evidence' => $evidence,
        ];
    }

    /** Buys the competitor rows of a queued run, then builds the ideas. */
    public function execute(int $runId): void
    {
        $run = $this->ideas->run($runId);
        if ($run === null || $run->status !== ArticleIdeaRun::QUEUED) {
            return;
        }
        $project = $this->project($run->workspaceId, $run->projectId);
        if ($project === null || !$this->features->enabled(Feature::KeywordMetrics)) {
            $this->ideas->fail($run->id, 'The project or the keyword data feature is no longer available.', $this->now());

            return;
        }
        $this->ideas->markRunning($run->id);
        // Exactly the markets confirmed with the run (a queued run from before markets: its one market, or today's first).
        $locales = array_values(array_filter(array_map(KeywordLocale::fromKey(...), $run->marketKeys())));
        if ($locales === []) {
            $locales = [$this->marketPlan($project)['markets'][0] ?? KeywordLocale::default()];
        }
        try {
            foreach ($locales as $locale) {
                // Never pay for a market DataForSEO Labs has no data for.
                if (Markets::labsProblem($locale->locationCode, $locale->languageCode) !== null) {
                    continue;
                }
                foreach ($run->competitors as $competitor) {
                    $rows = array_map(static fn(ResearchKeyword $k): array => [
                        'domain' => $competitor['domain'], 'found' => $competitor['found'], 'url' => (string) $k->url, 'keyword' => $k->keyword,
                        'volume' => $k->searchVolume, 'difficulty' => $k->difficulty, 'position' => $k->position, 'traffic' => $k->traffic, 'avg_rd' => $k->avgReferringDomains,
                        'market' => $locale->key(),
                    ], array_filter($this->provider->rankedKeywords($run->workspaceId, $run->projectId, $competitor['domain'], $locale, self::ROWS_PER_COMPETITOR), static fn(ResearchKeyword $k): bool => $k->url !== null && $k->url !== ''));
                    $this->ideas->saveRows($run->id, $run->projectId, array_values($rows));
                }
            }
        } catch (IntegrationException $e) {
            $this->ideas->fail($run->id, mb_substr($e->getMessage(), 0, 500), $this->now());

            return;
        }
        $this->ideas->finish($run->id, $this->now());
        $this->ideas->pruneRows($run->projectId, 2);
        $this->rebuild($run->projectId, $run->workspaceId);
    }

    /**
     * Builds the project's ideas from its newest finished run, for free (no
     * provider call). Rows are grouped per market (rows saved before markets
     * belong to the run's market), built per market, then joined.
     * Returns how many.
     */
    public function rebuild(int $projectId, int $workspaceId): int
    {
        $run = $this->ideas->latestDoneRun($projectId);
        if ($run === null || $run->workspaceId !== $workspaceId) {
            return 0;
        }
        /** @var ProjectSignals $signals */
        $signals = ($this->signals)($projectId, $workspaceId);
        $relevance = new Relevance($signals->business, $signals->keywordRatings);
        $ownDomain = $signals->domain;
        $ownRd = null;
        foreach ($signals->backlinkProfiles as $profile) {
            if ($ownDomain !== null && strtolower($profile->domain) === strtolower($ownDomain)) {
                $ownRd = $profile->referringDomains;
            }
        }
        $project = $this->calibration !== null ? $this->project($workspaceId, $projectId) : null;
        $fallback = $run->marketKeys()[0] ?? KeywordLocale::default()->key();
        $byMarket = array_fill_keys($run->marketKeys(), []);
        foreach ($this->ideas->rows($run->id) as $row) {
            $byMarket[$row['market'] ?? $fallback][] = $row;
        }
        $coverage = $this->coverage($signals);
        $calibration = $project !== null && $this->calibration !== null ? ($this->calibration)($project) : [];
        $built = [];
        foreach ($byMarket as $market => $rows) {
            $built[(string) $market] = IdeaBuilder::build(
                $rows,
                $coverage,
                static fn(string $keyword): bool => $relevance->of($keyword)['verdict'] !== Relevance::NOT_RELEVANT,
                static fn(int $position): float => $signals->expectedCtr($position),
                $ownRd,
                $calibration,
                (string) $market,
            );
        }
        $ideas = IdeaBuilder::merge($built);
        $this->ideas->replaceIdeas($projectId, $workspaceId, $run->id, $ideas, $this->now());

        return count($ideas);
    }

    public function dismiss(User $user, Project $project, string $key, ClientInfo $client): ArticleIdea
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $idea = $this->ideas->idea($project, $key) ?? throw new NotFoundHttpException();
        $this->ideas->dismiss($project, $key, $idea->mainKeyword, $user->id, $this->now());
        $this->audit->record(new AuditEvent('article_ideas.dismissed', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['keyword' => $idea->mainKeyword]), $client);

        return $idea;
    }

    /**
     * Competitors for a run: tracked ones first, then the domains seen most often
     * in the stored page-one results (not generic platforms).
     *
     * @return list<array{domain: string, found: bool}>
     */
    public function plannedCompetitors(Project $project): array
    {
        $own = self::bare($project->primaryDomain);
        $out = [];
        foreach ($this->competitors->forProject($project->id) as $competitor) {
            $out[self::bare($competitor->domain)] = ['domain' => self::bare($competitor->domain), 'found' => false];
        }
        foreach ([SerpDevice::Desktop, SerpDevice::Mobile] as $device) {
            foreach ($this->serps->domains($project->id, $device, 15) as $domain) {
                $domain = self::bare($domain);
                if ($domain !== $own && !isset($out[$domain]) && !in_array($domain, self::GENERIC, true) && !str_ends_with($domain, '.wikipedia.org')) {
                    $out[$domain] = ['domain' => $domain, 'found' => true];
                }
            }
        }
        unset($out[$own]);

        return array_slice(array_values($out), 0, self::MAX_COMPETITORS);
    }

    /**
     * Your best page per search: Search Console query pages (impression-weighted
     * position) and your ranked keywords from keyword research.
     *
     * @return array<string, array{url: string, position: ?float, impressions: int}>
     */
    private function coverage(ProjectSignals $signals): array
    {
        $own = [];
        foreach ($signals->queryPages as $row) {
            $query = Keyword::normalize($row->query);
            if ($query === '' || $row->metrics->impressions <= 0) {
                continue;
            }
            if ($row->metrics->position < ($own[$query]['position'] ?? INF)) {
                $own[$query] = ['url' => $row->url, 'position' => $row->metrics->position, 'impressions' => $row->metrics->impressions];
            }
        }
        foreach ($signals->researchRanked as $keyword) {
            $query = Keyword::normalize($keyword->keyword);
            if ($keyword->url !== null && $keyword->position !== null && $keyword->position < ($own[$query]['position'] ?? INF)) {
                $own[$query] = ['url' => $keyword->url, 'position' => (float) $keyword->position, 'impressions' => $own[$query]['impressions'] ?? 0];
            }
        }

        return $own;
    }

    private function latestDone(Project $project): ?ArticleIdeaRun
    {
        $run = $this->ideas->latestDoneRun($project->id);

        return $run !== null && $run->workspaceId === $project->workspaceId ? $run : null;
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

    private static function bare(string $domain): string
    {
        return (string) preg_replace('/^www\./', '', strtolower(trim($domain)));
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
