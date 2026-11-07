<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Backlinks\AnchorText;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\BacklinksProvider;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\Research\ResearchResult;

/**
 * Backlinks of a project from the provider's link index: the link profiles
 * of the project and its competitors (with history), referring domains,
 * new and lost referring domains of the last 30 days, anchor texts and the
 * link gap to the competitors. Lookups cost money (analysis.execute,
 * feature backlinks), run as jobs and are stored in
 * research_results under the bl_* kinds.
 */
final readonly class BacklinksService
{
    public const array KINDS = ['bl_profiles', 'bl_domains', 'bl_new', 'bl_lost', 'bl_anchors', 'bl_gap', 'bl_links', 'bl_lost_links'];
    public const array LIMITS = ['bl_domains' => 500, 'bl_new' => 200, 'bl_lost' => 200, 'bl_anchors' => 100, 'bl_gap' => 300, 'bl_links' => 1000, 'bl_lost_links' => 1000];
    public const int RECENT_DAYS = 30;
    private const int MONTH_DAYS = 30;

    public function __construct(
        private ResearchRepository $results,
        private BacklinksScheduler $scheduler,
        private BacklinksProvider $provider,
        private BacklinkHistory $history,
        private CompetitorRepository $competitors,
        private ProjectService $projects,
        private ProjectRepository $projectRepository,
        private FeatureManager $features,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{
     *     results: array<string, ?ResearchResult>,
     *     competitors: list<Competitor>,
     *     history: list<array{date: string, profiles: array<string, BacklinkProfile>}>,
     *     monthly: bool,
     *     monitorLost: bool,
     *     ownDomain: string,
     *     canRun: bool,
     *     canEdit: bool,
     *     available: bool,
     *     featureOn: bool,
     *     costs: array<string, float>
     * }
     */
    public function page(User $user, Project $project): array
    {
        $competitors = $this->competitors->forProject($project->id);
        $results = [];
        foreach (self::KINDS as $kind) {
            $results[$kind] = $this->results->find($project->id, $kind);
        }
        $costs = ['bl_profiles' => $this->provider->estimatedCost(1 + count($competitors), 1 + count($competitors))];
        foreach (self::LIMITS as $kind => $limit) {
            $costs[$kind] = $this->provider->estimatedCost(1, $limit);
        }

        return [
            'results' => $results,
            'competitors' => $competitors,
            'history' => $this->history->history($project->id, 12),
            'monthly' => $this->history->isMonthly($project->id),
            'monitorLost' => $this->history->monitorsLost($project->id),
            'ownDomain' => self::bareDomain($project->primaryDomain),
            'canRun' => $this->projects->can($user, Permission::AnalysisExecute, $project),
            'canEdit' => $this->projects->can($user, Permission::ProjectsEdit, $project),
            'available' => $this->provider->isAvailable($project->workspaceId),
            'featureOn' => $this->features->enabled(Feature::Backlinks),
            'costs' => $costs,
        ];
    }

    public function request(User $user, Project $project, string $kind, ClientInfo $client, bool $runAgain = false): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new HttpException(400, 'Unknown lookup.');
        }
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot start paid analyses for this project.');
        }
        $this->features->require(Feature::Backlinks);
        if (!$this->provider->isAvailable($project->workspaceId)) {
            throw new HttpException(409, 'Connect DataForSEO under Integrations first.');
        }
        $params = $this->params($project);
        if ($kind === 'bl_gap' && $params['competitors'] === []) {
            throw new HttpException(409, 'Add competitors first.');
        }
        if ($this->results->find($project->id, $kind)?->isQueued() ?? false) {
            throw new HttpException(409, 'This lookup is already queued.');
        }
        \SeoControlCenter\Modules\Research\RepeatedLookup::guard($this->results->find($project->id, $kind), $params, $this->clock->now(), $runAgain, \SeoControlCenter\Modules\Research\RepeatedLookup::LABELS[$kind]);
        if (!$this->rateLimiter->hit('backlinks:project:' . $project->id, 30, 3600)->allowed) {
            throw new HttpException(429, 'Many lookups were started in the last hour. Please try again later.');
        }
        $this->queue($project, $kind, \SeoControlCenter\Modules\Research\ResumableLookup::resumeParams($this->results->find($project->id, $kind), $params, $this->clock->now()), $user->id);
        $this->audit->record(new AuditEvent('backlinks.requested', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'kind' => $kind,
        ]), $client);
    }

    public function setLostMonitoring(User $user, Project $project, bool $enabled, ClientInfo $client): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project) || !$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot change paid monitoring for this project.');
        }
        if ($enabled) {
            $this->features->require(Feature::Backlinks);
        }
        $this->history->setLostMonitoring($project->id, $project->workspaceId, $enabled);
        $this->audit->record(new AuditEvent(
            'backlinks.lost_monitoring_saved',
            ActorType::User,
            actorUserId: $user->id,
            actorLabel: $user->email,
            workspaceId: $project->workspaceId,
            targetType: 'project',
            targetId: $project->publicId,
            metadata: ['enabled' => $enabled]
        ), $client);
    }

    public function setMonthly(User $user, Project $project, bool $monthly, ClientInfo $client): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change the settings of this project.');
        }
        $this->history->setMonthly($project->id, $project->workspaceId, $monthly);
        $this->audit->record(new AuditEvent('backlinks.monthly_saved', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'monthly' => $monthly,
        ]), $client);
    }

    /**
     * Job: runs a queued lookup and stores its rows (and, for link profiles, the history).
     *
     * @throws IntegrationException when DataForSEO is temporarily unavailable and $lastAttempt is false
     */
    public function run(int $projectId, int $workspaceId, string $kind, bool $lastAttempt): void
    {
        $activeProject = null;
        foreach ($this->projectRepository->allInWorkspace($workspaceId) as $project) {
            if ($project->id === $projectId) {
                $activeProject = $project;
                break;
            }
        }
        if ($activeProject === null || !$this->features->enabled(Feature::Backlinks)) {
            return;
        }
        $result = $this->results->find($projectId, $kind);
        if ($result === null || !$result->isQueued() || !in_array($kind, self::KINDS, true)) {
            return;
        }
        if (($result->params['scheduled'] ?? false) === true) {
            $enabled = match ($kind) {
                'bl_profiles' => $this->history->isMonthly($projectId),
                'bl_lost', 'bl_lost_links' => $this->history->monitorsLost($projectId),
                default => true,
            };
            if (!$enabled) {
                $this->results->fail($projectId, $kind, '', 'Recurring backlink checks were disabled. No provider requests were made.');
                return;
            }
        }
        $domain = is_string($result->params['domain'] ?? null) ? $result->params['domain'] : '';
        if (self::bareDomain($domain) !== self::bareDomain($activeProject->primaryDomain)) {
            $this->results->fail($projectId, $kind, '', 'The project domain changed. Start a new lookup for the current domain. No provider requests were made.');
            return;
        }
        $competitors = array_values(array_filter((array) ($result->params['competitors'] ?? []), is_string(...)));
        if (in_array($kind, ['bl_profiles', 'bl_gap'], true) && array_diff($competitors, array_map(static fn(Competitor $c): string => $c->domain, $this->competitors->forProject($projectId))) !== []) {
            $this->results->fail($projectId, $kind, '', 'A queued competitor was removed or changed. Start a lookup with the current competitors. No provider requests were made.');
            return;
        }
        $since = $this->clock->now()->modify('-' . self::RECENT_DAYS . ' days')->format('Y-m-d');
        $domainRows = static fn(array $list): array => array_map(static fn(ReferringDomain $d): array => $d->toArray(), $list);
        try {
            $profiles = [];
            $rows = match ($kind) {
                'bl_profiles' => $this->profileRows($workspaceId, $projectId, [$domain, ...$competitors], new \SeoControlCenter\Modules\Research\ResumableLookup($this->results, $result)),
                'bl_domains' => $domainRows($this->provider->referringDomains($workspaceId, $projectId, $domain, self::LIMITS['bl_domains'])),
                'bl_new' => $domainRows($this->provider->referringDomains($workspaceId, $projectId, $domain, self::LIMITS['bl_new'], $since)),
                'bl_lost' => $domainRows($this->provider->referringDomains($workspaceId, $projectId, $domain, self::LIMITS['bl_lost'], $since, true)),
                'bl_anchors' => array_map(static fn(AnchorText $a): array => $a->toArray(), $this->provider->anchors($workspaceId, $projectId, $domain, self::LIMITS['bl_anchors'])),
                'bl_links', 'bl_lost_links' => array_map(static fn(\SeoControlCenter\Domain\Backlinks\PageBacklink $l): array => $l->toArray(), $this->provider->pageLinks($workspaceId, $projectId, $domain, self::LIMITS[$kind], $kind === 'bl_lost_links' ? $since : null, $kind === 'bl_lost_links')),
                default => $domainRows($this->provider->linkGap($workspaceId, $projectId, $domain, $competitors, self::LIMITS['bl_gap'])),
            };
        } catch (BudgetExceededException $e) {
            $this->results->fail($projectId, $kind, '', $e->getMessage());

            return;
        } catch (IntegrationException $e) {
            if ($e->retryable && !$e->chargeUncertain && !$lastAttempt) {
                throw $e;
            }
            $this->results->fail($projectId, $kind, '', $e->getMessage());

            return;
        }
        $this->results->complete($projectId, $kind, '', array_values($rows));
        if ($kind === 'bl_profiles') {
            $profiles = array_values(array_filter(array_map(BacklinkProfile::fromArray(...), $rows)));
        }
        if ($kind === 'bl_lost_links') {
            $this->history->recordLostCheck($projectId, $this->clock->now()->format('Y-m-d'));
        }
        if ($kind === 'bl_profiles' && $profiles !== []) {
            $this->history->record($projectId, $workspaceId, $this->clock->now()->format('Y-m-d'), $profiles);
        }
        $this->logger->info('Backlink lookup stored', ['project_id' => $projectId, 'kind' => $kind, 'rows' => count($rows)]);
    }

    /**
     * Daily job: queues the monthly link profile checks that are due.
     *
     * @return int checks queued
     */
    public function startDue(): int
    {
        if (!$this->features->enabled(Feature::Backlinks)) {
            return 0;
        }
        $queued = 0;
        foreach ($this->history->dueMonthly($this->clock->now()->modify('-' . self::MONTH_DAYS . ' days')->format('Y-m-d')) as $row) {
            $project = $this->projectRepository->findInWorkspace($row['workspaceId'], $row['projectPublicId']);
            if ($project === null || $project->id !== $row['projectId'] || !$this->provider->isAvailable($project->workspaceId)) {
                continue;
            }
            if ($this->results->find($project->id, 'bl_profiles')?->isQueued() ?? false) {
                continue;
            }
            $params = \SeoControlCenter\Modules\Research\ResumableLookup::scheduledParams($this->results->find($project->id, 'bl_profiles'), [...$this->params($project), 'scheduled' => true]);
            if ($params === null) {
                continue;
            }
            $this->queue($project, 'bl_profiles', $params, null);
            $queued++;
        }

        foreach ($this->history->dueLost($this->clock->now()->modify('-7 days')->format('Y-m-d')) as $row) {
            $project = $this->projectRepository->findInWorkspace($row['workspaceId'], $row['projectPublicId']);
            if ($project !== null && $project->id === $row['projectId'] && $this->provider->isAvailable($project->workspaceId)
                && !($this->results->find($project->id, 'bl_lost_links')?->isQueued() ?? false)) {
                $this->queue($project, 'bl_lost_links', [...$this->params($project), 'scheduled' => true], null);
                ++$queued;
                if (!($this->results->find($project->id, 'bl_lost')?->isQueued() ?? false)) {
                    $this->queue($project, 'bl_lost', [...$this->params($project), 'scheduled' => true], null);
                    ++$queued;
                }
            }
        }
        return $queued;
    }

    /**
     * Sorts referring domains by a column; unknown columns sort by rank.
     *
     * @param list<ReferringDomain> $domains
     * @return list<ReferringDomain>
     */
    public static function sortDomains(array $domains, string $column, string $direction): array
    {
        $value = static fn(ReferringDomain $d): string|int|null => match ($column) {
            'domain' => $d->domain,
            'backlinks' => $d->backlinks,
            'spam' => $d->spamScore,
            'first_seen' => $d->firstSeen,
            'lost' => $d->lostDate,
            'targets' => $d->targets,
            default => $d->rank,
        };
        usort($domains, static function (ReferringDomain $a, ReferringDomain $b) use ($value, $direction): int {
            $x = $value($a);
            $y = $value($b);
            if ($x === null || $y === null) {
                return [$x === null, $a->domain] <=> [$y === null, $b->domain];
            }

            return ($direction === 'asc' ? $x <=> $y : $y <=> $x) ?: strcmp($a->domain, $b->domain);
        });

        return $domains;
    }

    /**
     * @return array{domain: string, competitors: list<string>}
     */
    private function params(Project $project): array
    {
        return [
            'domain' => self::bareDomain($project->primaryDomain),
            'competitors' => array_map(static fn(Competitor $c): string => $c->domain, $this->competitors->forProject($project->id)),
        ];
    }

    /** @param list<string> $domains
     * @return list<array<mixed>>
     */
    private function profileRows(int $workspaceId, int $projectId, array $domains, \SeoControlCenter\Modules\Research\ResumableLookup $batch): array
    {
        $rows = [];
        foreach (array_unique($domains) as $domain) {
            array_push($rows, ...$batch->step('profiles:' . $domain, fn(): array => array_map(static fn(BacklinkProfile $p): array => $p->toArray(), $this->provider->profiles($workspaceId, $projectId, [$domain]))));
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function queue(Project $project, string $kind, array $params, ?int $userId): void
    {
        $this->results->queue($project->id, $project->workspaceId, $kind, '', $params, $userId);
        $this->scheduler->fetch($project->id, $project->workspaceId, $kind);
    }

    public static function bareDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }
}
