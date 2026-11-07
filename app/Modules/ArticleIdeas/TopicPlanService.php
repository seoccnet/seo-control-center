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
use SeoControlCenter\Domain\ArticleIdeas\IdeaBuilder;
use SeoControlCenter\Domain\ArticleIdeas\PlanProposer;
use SeoControlCenter\Domain\ArticleIdeas\TopicPlan;
use SeoControlCenter\Domain\ArticleIdeas\TopicPlanRepository;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\BacklinksProvider;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;

/**
 * Topic plans (ADR-041, G2): a pillar idea you pick, the members SEOcc
 * proposes and you keep, a free link map built in a job, and a paid,
 * confirmed backlink check of the pillar's top competitor pages.
 */
final readonly class TopicPlanService
{
    /** Competitor pages of the pillar whose backlinks are bought. */
    public const int BACKLINK_PAGES = 2;
    public const int PHRASES = 8;

    /**
     * @param \Closure(int, int): ProjectSignals $signals
     * @param (\Closure(Project, list<array{id: int, topic: string, cluster_id: ?string, phrases: list<string>, demand: float}>): array{audit: ?string, rows: list<array{source_url: string, target_id: int, anchor: string, paragraph: string}>})|null $plannedLinks
     */
    public function __construct(
        private TopicPlanRepository $plans,
        private ArticleIdeaRepository $ideas,
        private ArticleIdeaScheduler $scheduler,
        private ?BacklinksProvider $backlinks,
        private \Closure $signals,
        private ?\Closure $plannedLinks,
        private ProjectRepository $projects,
        private Authorizer $auth,
        private FeatureManager $features,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /** @return list<TopicPlan> */
    public function plans(Project $project): array
    {
        return $this->plans->forProject($project, 100);
    }

    /**
     * @return array{plan: TopicPlan, pillar: ?ArticleIdea, ideas: array<string, ArticleIdea>, backlink_urls: list<string>, backlink_cost: float, backlinks_available: bool, backlinks_enabled: bool, repeat_since: string, can_edit: bool}
     */
    public function view(User $user, Project $project, string $publicId): array
    {
        $plan = $this->plans->find($project, $publicId) ?? throw new NotFoundHttpException();
        $pillar = $this->ideas->idea($project, $plan->pillarKey);
        $ideas = [];
        foreach ($plan->members as $member) {
            if ($member['type'] === TopicPlan::IDEA && ($idea = $this->ideas->idea($project, $member['key'])) !== null) {
                $ideas[$idea->key] = $idea;
            }
        }
        $urls = $pillar !== null ? self::backlinkUrls($pillar) : [];

        return [
            'plan' => $plan,
            'pillar' => $pillar,
            'ideas' => $ideas,
            'backlink_urls' => $urls,
            'backlink_cost' => $this->backlinks?->estimatedCost(max(1, count($urls)), count($urls)) ?? 0.0,
            'backlinks_available' => $this->backlinks?->isAvailable($project->workspaceId) ?? false,
            'backlinks_enabled' => $this->features->enabled(Feature::Backlinks),
            'repeat_since' => $this->clock->now()->modify('-' . TopicPlan::BACKLINKS_REPEAT_DAYS . ' days')->format('Y-m-d H:i:s'),
            'can_edit' => $this->auth->can($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id),
        ];
    }

    /** Creates the plan of a pillar idea (or opens the one it has) and queues its link map. */
    public function create(User $user, Project $project, string $ideaKey, ClientInfo $client): TopicPlan
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->features->require(Feature::KeywordMetrics);
        $pillar = $this->ideas->idea($project, $ideaKey) ?? throw new NotFoundHttpException();
        $existing = $this->plans->forPillar($project, $ideaKey);
        if ($existing !== null) {
            return $existing;
        }
        if (!$this->limiter->hit('topic_plans:create:project:' . $project->id, 30, 3600)->allowed) {
            throw new HttpException(429, 'Many topic plans were created this hour. Wait a while before creating more.');
        }
        /** @var ProjectSignals $signals */
        $signals = ($this->signals)($project->id, $project->workspaceId);
        $queries = array_map(static fn($row): array => ['query' => $row->query, 'url' => $row->url, 'impressions' => $row->metrics->impressions], $signals->queryPages);
        $plan = $this->plans->create($project, $pillar->key, $pillar->mainKeyword, PlanProposer::propose($pillar, $this->ideas->ideas($project, 200), $queries), $user->id, $this->now());
        $this->queueLinks($plan);
        $this->audit->record(new AuditEvent('topic_plans.created', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'topic_plan', targetId: $plan->publicId, metadata: ['pillar' => $pillar->mainKeyword]), $client);

        return $plan;
    }

    /**
     * Keeps the members whose keys are given and drops the others, then rebuilds the link map.
     *
     * @param list<string> $kept
     */
    public function keep(User $user, Project $project, string $publicId, array $kept): TopicPlan
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $plan = $this->plans->find($project, $publicId) ?? throw new NotFoundHttpException();
        $keep = array_flip($kept);
        $members = array_map(static fn(array $m): array => [...$m, 'kept' => isset($keep[$m['key']])], $plan->members);
        $this->plans->saveMembers($plan->id, $members, $this->now());
        $this->queueLinks($plan);

        return $this->plans->byId($plan->id) ?? $plan;
    }

    public function requestLinks(User $user, Project $project, string $publicId): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $plan = $this->plans->find($project, $publicId) ?? throw new NotFoundHttpException();
        if (!$this->limiter->hit('topic_plans:links:project:' . $project->id, 20, 3600)->allowed) {
            throw new HttpException(429, 'The link map was rebuilt many times this hour. Wait a while before rebuilding it again.');
        }
        if (!$this->queueLinks($plan)) {
            throw new HttpException(409, 'The link map of this plan is already being built.');
        }
    }

    /** Starts the paid backlink check of the pillar's top competitor pages. */
    public function requestBacklinks(User $user, Project $project, string $publicId, bool $confirmPaid, bool $confirmRepeat, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->features->require(Feature::Backlinks);
        $plan = $this->plans->find($project, $publicId) ?? throw new NotFoundHttpException();
        if ($this->backlinks === null || !$this->backlinks->isAvailable($project->workspaceId)) {
            throw new HttpException(409, 'Connect DataForSEO under Integrations first.');
        }
        $pillar = $this->ideas->idea($project, $plan->pillarKey);
        $urls = $pillar !== null ? self::backlinkUrls($pillar) : [];
        if ($urls === []) {
            throw new HttpException(409, 'The pillar idea has no competitor pages to check. Rebuild the article ideas first.');
        }
        $cost = $this->backlinks->estimatedCost(count($urls), count($urls));
        if (!$confirmPaid) {
            throw new HttpException(422, sprintf('Checking the backlinks of %d competitor page%s costs about $%.2f at DataForSEO. Confirm the paid check.', count($urls), count($urls) === 1 ? '' : 's', $cost));
        }
        if ($plan->backlinksStatus === TopicPlan::DONE && !$confirmRepeat && (string) $plan->backlinksAt >= $this->clock->now()->modify('-' . TopicPlan::BACKLINKS_REPEAT_DAYS . ' days')->format('Y-m-d H:i:s')) {
            throw new HttpException(422, sprintf('The backlinks were checked on %s. Tick “Check again and pay” to buy them again.', substr((string) $plan->backlinksAt, 0, 10)));
        }
        if (!$this->limiter->hit('topic_plans:backlinks:project:' . $project->id, 5, 3600)->allowed) {
            throw new HttpException(429, 'Wait an hour before checking more backlinks.');
        }
        if (!$this->plans->queueBacklinks($plan->id, $cost, $this->now())) {
            throw new HttpException(409, 'The backlinks of this plan are already being checked.');
        }
        $this->scheduler->queuePlanBacklinks($plan->id, $project->workspaceId);
        $this->audit->record(new AuditEvent('topic_plans.backlinks_requested', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'topic_plan', targetId: $plan->publicId, metadata: ['pages' => $urls, 'estimated_cost' => $cost]), $client);
    }

    public function delete(User $user, Project $project, string $publicId, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $plan = $this->plans->find($project, $publicId) ?? throw new NotFoundHttpException();
        $this->plans->delete($project, $plan->id);
        $this->audit->record(new AuditEvent('topic_plans.deleted', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'topic_plan', targetId: $plan->publicId, metadata: ['pillar' => $plan->pillarKeyword]), $client);
    }

    /**
     * Builds a plan's link map (job): pillar ↔ kept articles, and links from
     * your crawled pages to the planned articles with the paragraph to use.
     */
    public function buildLinks(int $planId): void
    {
        $plan = $this->plans->byId($planId);
        $project = $plan !== null ? $this->project($plan->workspaceId, $plan->projectId) : null;
        if ($plan === null || $project === null) {
            return;
        }
        $pillar = $this->ideas->idea($project, $plan->pillarKey);
        $articles = [];
        foreach ($plan->members as $member) {
            if ($member['kept'] && in_array($member['type'], [TopicPlan::IDEA, TopicPlan::SEARCH], true)) {
                $articles[] = $member;
            }
        }
        $internal = [];
        foreach ($articles as $member) {
            $internal[] = ['from' => 'pillar', 'to' => $member['key'], 'anchor' => $member['label']];
            $internal[] = ['from' => $member['key'], 'to' => 'pillar', 'anchor' => $plan->pillarKeyword];
        }
        foreach ($plan->kept(TopicPlan::PAGE) as $page) {
            $internal[] = ['from' => $page['key'], 'to' => 'pillar', 'anchor' => $plan->pillarKeyword];
        }
        // Your crawled pages: the F1 link suggester with the planned articles as targets.
        $keys = ['pillar'];
        $targets = [['id' => -1, 'topic' => $plan->pillarKeyword, 'cluster_id' => null, 'phrases' => self::phrases($plan->pillarKeyword, $pillar), 'demand' => (float) ($pillar->volume ?? 0)]];
        foreach ($articles as $i => $member) {
            $idea = $member['type'] === TopicPlan::IDEA ? $this->ideas->idea($project, $member['key']) : null;
            $keys[] = $member['key'];
            $targets[] = ['id' => -2 - $i, 'topic' => $member['label'], 'cluster_id' => null, 'phrases' => self::phrases($member['label'], $idea), 'demand' => (float) $member['volume']];
        }
        $found = $this->plannedLinks !== null ? ($this->plannedLinks)($project, $targets) : ['audit' => null, 'rows' => []];
        $pages = [];
        foreach ($found['rows'] as $row) {
            $key = $keys[-1 - $row['target_id']] ?? null;
            if ($key !== null) {
                $pages[] = ['source_url' => $row['source_url'], 'to' => $key, 'anchor' => $row['anchor'], 'paragraph' => mb_substr($row['paragraph'], 0, 400)];
            }
        }
        $this->plans->saveLinks($plan->id, ['internal' => $internal, 'pages' => array_slice($pages, 0, 60), 'audit' => $found['audit']], $this->now());
    }

    /** Buys the backlinks of the pillar's top competitor pages (job). */
    public function checkBacklinks(int $planId): void
    {
        $plan = $this->plans->byId($planId);
        $project = $plan !== null ? $this->project($plan->workspaceId, $plan->projectId) : null;
        if ($plan === null || $plan->backlinksStatus !== TopicPlan::QUEUED) {
            return;
        }
        if ($project === null || $this->backlinks === null || !$this->features->enabled(Feature::Backlinks)) {
            $this->plans->failBacklinks($plan->id, 'The project or the backlinks feature is no longer available.', $this->now());

            return;
        }
        $pillar = $this->ideas->idea($project, $plan->pillarKey);
        $urls = $pillar !== null ? self::backlinkUrls($pillar) : [];
        try {
            $profiles = $urls === [] ? [] : $this->backlinks->pageProfiles($plan->workspaceId, $plan->projectId, $urls);
        } catch (IntegrationException $e) {
            $this->plans->failBacklinks($plan->id, $e->getMessage(), $this->now());

            return;
        }
        $this->plans->saveBacklinks($plan->id, array_map(static fn(BacklinkProfile $p): array => ['url' => $p->domain, 'referring_domains' => $p->referringDomains, 'backlinks' => $p->backlinks, 'rank' => $p->rank], $profiles), $this->now());
    }

    /**
     * The plan's internal links of one idea, as brief lines (for briefs prefilled from ideas).
     *
     * @return list<string>
     */
    public function briefLinks(Project $project, string $ideaKey): array
    {
        $lines = [];
        foreach ($this->plans->forProject($project, 100) as $plan) {
            $self = $plan->pillarKey === $ideaKey ? 'pillar' : (in_array($ideaKey, array_column($plan->kept(TopicPlan::IDEA), 'key'), true) ? $ideaKey : null);
            if ($self === null) {
                continue;
            }
            foreach ($plan->links['internal'] ?? [] as $link) {
                if ($link['from'] === $self) {
                    $lines[] = sprintf('Topic plan "%s": link to the article on %s with the anchor "%s".', $plan->pillarKeyword, $plan->label($link['to']), $link['anchor']);
                }
            }
            foreach ($plan->links['pages'] ?? [] as $link) {
                if ($link['to'] === $self) {
                    $lines[] = sprintf('Topic plan "%s": once published, link from %s with the anchor "%s".', $plan->pillarKeyword, $link['source_url'], $link['anchor']);
                }
            }
        }

        return array_slice($lines, 0, 20);
    }

    /** @return list<string> the pillar's top competitor pages, best position first, language copies once */
    public static function backlinkUrls(ArticleIdea $pillar): array
    {
        $competitors = $pillar->competitors;
        usort($competitors, static fn(array $a, array $b): int => [$a['position'] ?? PHP_INT_MAX, $a['url']] <=> [$b['position'] ?? PHP_INT_MAX, $b['url']]);
        $urls = [];
        foreach ($competitors as $competitor) {
            $urls[IdeaBuilder::articleKey($competitor['url'])] ??= $competitor['url'];
        }

        return array_slice(array_values($urls), 0, self::BACKLINK_PAGES);
    }

    /** @return list<string> the searches a link to the article can use as anchor */
    private static function phrases(string $label, ?ArticleIdea $idea): array
    {
        $phrases = [$label];
        foreach ($idea->keywords ?? [] as $keyword) {
            $phrases[] = $keyword['keyword'];
        }

        return array_slice(array_values(array_unique($phrases)), 0, self::PHRASES);
    }

    private function queueLinks(TopicPlan $plan): bool
    {
        if (!$this->plans->queueLinks($plan->id, $this->now())) {
            return false;
        }
        $this->scheduler->queuePlanLinks($plan->id, $plan->workspaceId);

        return true;
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

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
