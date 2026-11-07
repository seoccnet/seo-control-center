<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;

/**
 * Application service for opportunities: the list with filters, status
 * changes and on-demand analysis (projects.edit; analysis is free).
 */
final readonly class OpportunityService
{
    public const int PAGE_SIZE = 50;
    /** Statuses a user may set. */
    private const array USER_STATUSES = [StoredOpportunity::OPEN, StoredOpportunity::DONE, StoredOpportunity::DISMISSED];

    public function __construct(
        private OpportunityRepository $opportunities,
        private OpportunityAnalyzer $analyzer,
        private ProjectService $projects,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private FeatureManager $features,
        private OpportunityCatalog $catalog,
        private OutcomeService $outcomes,
        private \SeoControlCenter\Domain\Opportunities\OutcomeRepository $outcomeRepository,
        private \SeoControlCenter\Core\Support\Clock $clock,
        private \SeoControlCenter\Domain\Opportunities\PlaybookLibrary $playbooks,
    ) {}

    /**
     * @return array{opportunities: list<StoredOpportunity>, status: string, type: ?OpportunityType, openByType: array<string, int>,
     *     statusCounts: array<string, int>, page: int, pages: int, lastAnalyzedAt: ?string, catalogTruncated: bool, filters: \SeoControlCenter\Domain\Opportunities\OpportunityFilter, coverage: array<string, array{status: string, date: ?string, notice?: string}>, canEdit: bool}
     */
    public function overview(User $user, Project $project, string $status, ?OpportunityType $type, int $page, ?\SeoControlCenter\Domain\Opportunities\OpportunityFilter $filter = null): array
    {
        $status = in_array($status, StoredOpportunity::STATUSES, true) ? $status : StoredOpportunity::OPEN;
        $snapshot = $this->catalog->snapshot($project->id);
        $rows = $this->catalog->list($project->id, $status, $type, $filter)['rows'];
        $pages = max(1, (int) ceil(count($rows) / self::PAGE_SIZE));
        $page = max(1, min($pages, $page));
        $statusCounts = [];
        foreach (StoredOpportunity::STATUSES as $s) {
            $statusCounts[$s] = count($snapshot['groups'][$s]);
        }

        return [
            'opportunities' => array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            'status' => $status,
            'filters' => $filter ?? new \SeoControlCenter\Domain\Opportunities\OpportunityFilter(),
            'type' => $type,
            'openByType' => array_count_values(array_map(static fn(StoredOpportunity $row): string => $row->type->value, $snapshot['groups'][StoredOpportunity::OPEN])),
            'statusCounts' => $statusCounts,
            'page' => $page,
            'pages' => $pages,
            'coverage' => \SeoControlCenter\Domain\Opportunities\SignalCoverage::summary($this->opportunities->coverage($project->id)),
            'catalogTruncated' => $snapshot['truncated'],
            'lastAnalyzedAt' => $this->opportunities->lastAnalyzedAt($project->id),
            'canEdit' => $this->projects->can($user, Permission::ProjectsEdit, $project),
        ];
    }

    /**
     * Opportunities with one status (and optionally one type), for the CSV
     * export. The caller has loaded the project (access checked).
     *
     * @return list<list<string|int|float|null>>
     */
    public function exportRows(Project $project, string $status, ?OpportunityType $type, ?\SeoControlCenter\Domain\Opportunities\OpportunityFilter $filter = null): array
    {
        $status = in_array($status, StoredOpportunity::STATUSES, true) ? $status : StoredOpportunity::OPEN;
        $effort = [Opportunity::EFFORT_LOW => 'low', Opportunity::EFFORT_MEDIUM => 'medium', Opportunity::EFFORT_HIGH => 'high'];
        $rows = [['Type', 'Title', 'Priority', 'Impact (0-100)', 'Confidence (0-100)', 'Effort', 'Status', 'First detected (UTC)', 'Last detected (UTC)', 'Explanation', 'Data']];
        foreach ($this->catalog->list($project->id, $status, $type, $filter)['rows'] as $opportunity) {
            $data = [];
            foreach ($opportunity->evidence as $item) {
                $data[] = $item['label'] . ': ' . $item['value'];
            }
            $rows[] = [
                $opportunity->type->label(),
                $opportunity->title,
                round($opportunity->priority, 2),
                $opportunity->impact,
                $opportunity->confidence,
                $effort[$opportunity->effort] ?? (string) $opportunity->effort,
                $opportunity->status,
                $opportunity->firstDetectedAt,
                $opportunity->lastDetectedAt,
                $opportunity->explanation,
                implode('; ', $data),
            ];
        }

        return $rows;
    }

    /** @return list<\SeoControlCenter\Domain\Opportunities\OpportunityOutcome> */
    public function outcomes(Project $project): array
    {
        return $this->outcomeRepository->recent($project->id);
    }

    public function canEdit(User $user, Project $project): bool
    {
        return $this->projects->can($user, Permission::ProjectsEdit, $project);
    }

    /**
     * Records whether the work behind a completed opportunity was really made
     * (ADR-039, E2). Not-implemented outcomes leave the calibration.
     *
     * @param string|null $via the AI client token name for MCP writes (ADR-036)
     */
    public function confirmOutcome(User $user, Project $project, string $outcomeId, bool $implemented, ClientInfo $client, ?string $via = null): \SeoControlCenter\Domain\Opportunities\OpportunityOutcome
    {
        $this->authorize($user, $project);
        $before = $this->outcomes->find($project, $outcomeId) ?? throw new NotFoundHttpException();
        $after = $this->outcomes->confirm($project, $outcomeId, $implemented, $via === null ? 'user' : 'mcp') ?? throw new NotFoundHttpException();
        $this->audit->record(new AuditEvent('opportunities.outcome_confirmed', $via === null ? ActorType::User : ActorType::Mcp, actorUserId: $user->id, actorLabel: $via ?? $user->email, workspaceId: $project->workspaceId, targetType: 'opportunity_outcome', targetId: $outcomeId, metadata: [
            'opportunity_id' => $before->opportunityId,
            'from' => $before->implementation,
            'to' => $after->implementation,
            ...($via === null ? [] : ['via' => $via]),
        ]), $client);

        return $after;
    }

    /** @return array{opportunities: list<StoredOpportunity>, truncated: bool, notice: string} */
    public function weekly(Project $project): array
    {
        $snapshot = $this->catalog->list($project->id, StoredOpportunity::OPEN);
        return ['opportunities' => (new \SeoControlCenter\Domain\Opportunities\OpportunityPrioritizer())->weekly($snapshot['rows']), 'truncated' => $snapshot['truncated'], 'notice' => 'Unblocked work first, then impact × confidence / effort. Up to three findings per primary type and ten total. Fewer than ten means the type cap or available evidence limited selection. Time-to-value ranges are planning assumptions.'];
    }

    /** @return array{plans: list<array{page: string, estimated_monthly_clicks: ?float, unknown_estimates: int, actions: list<StoredOpportunity>, notice: string}>, truncated: bool, action_plans: array<string, \SeoControlCenter\Domain\Opportunities\Playbook>} */
    public function pagePlans(Project $project): array
    {
        $snapshot = $this->catalog->list($project->id, StoredOpportunity::OPEN);
        $plans = array_slice((new \SeoControlCenter\Domain\Opportunities\OpportunityConsolidator())->pages($snapshot['rows']), 0, 200);
        $actionPlans = [];
        foreach ($plans as $plan) {
            foreach ($plan['actions'] as $action) {
                $actionPlans[$action->publicId] = $this->planFor($action);
            }
        }
        return ['plans' => $plans, 'truncated' => $snapshot['truncated'], 'action_plans' => $actionPlans];
    }

    /** @return array{opportunity: StoredOpportunity, canonical_subject: string, supporting: list<StoredOpportunity>, coverage: array<string, array{status: string, date: ?string, notice?: string}>, ordering_reason: string, catalog_truncated: bool, action_plan: \SeoControlCenter\Domain\Opportunities\Playbook} */
    public function detail(Project $project, string $id): array
    {
        $row = $this->opportunities->find($project->id, $id) ?? throw new NotFoundHttpException();
        $snapshot = $this->opportunities->catalog($project->id);
        $key = \SeoControlCenter\Domain\Opportunities\OpportunityConsolidator::key($row);
        $supporting = array_values(array_filter($snapshot['rows'], static fn(StoredOpportunity $other): bool => $other->publicId !== $row->publicId && \SeoControlCenter\Domain\Opportunities\OpportunityConsolidator::key($other) === $key));
        $coverage = $this->opportunities->coverage($project->id);
        $sources = [];
        foreach ($row->evidence as $item) {
            if ($item['label'] === 'Required sources') {
                $sources = explode(', ', $item['value']);
            }
        }
        $selected = $sources === [] ? \SeoControlCenter\Domain\Opportunities\SignalCoverage::summary($coverage) : [];
        foreach ($sources as $source) {
            $selected[$source] = $coverage[$source] ?? ['status' => 'missing', 'date' => null];
        }
        return ['opportunity' => $row, 'canonical_subject' => $key, 'supporting' => array_slice($supporting, 0, 20), 'coverage' => $selected, 'ordering_reason' => \SeoControlCenter\Domain\Opportunities\OpportunityPrioritizer::explanation($row, null), 'catalog_truncated' => $snapshot['total'] > count($snapshot['rows']),
            // Guidance only (ADR-031): the plan never changes impact, value or ordering.
            'action_plan' => $this->planFor($row)];
    }

    /** The action plan for a finding (ADR-031); guidance only. */
    public function planFor(StoredOpportunity $row): \SeoControlCenter\Domain\Opportunities\Playbook
    {
        return $this->playbooks->for($row->type, $row->subject, $row->evidence);
    }

    /** @param string|null $via the AI client token name for MCP writes (ADR-036) */
    public function snooze(User $user, Project $project, string $id, string $until, ClientInfo $client, ?string $via = null): void
    {
        $this->authorize($user, $project);
        $row = $this->opportunities->find($project->id, $id) ?? throw new NotFoundHttpException();
        $today = $this->clock->now()->setTimezone(new \DateTimeZone($project->timezone));
        try {
            new \SeoControlCenter\Domain\Shared\DateRange($until, $until);
        } catch (\InvalidArgumentException) {
            throw new HttpException(400, 'A valid snooze date is required.');
        }
        if ($until <= $today->format('Y-m-d') || $until > $today->modify('+365 days')->format('Y-m-d')) {
            throw new HttpException(400, 'Snooze date must be after today and no more than one year away.');
        }
        $this->opportunities->snooze($project->id, $id, $until, $user->id);
        $this->audit->record(new AuditEvent('opportunities.snoozed', $via === null ? ActorType::User : ActorType::Mcp, actorUserId: $user->id, actorLabel: $via, workspaceId: $project->workspaceId, targetType: 'opportunity', targetId: $id, metadata: ['from' => $row->status, 'from_until' => $row->snoozedUntil, 'until' => $until, ...($via === null ? [] : ['via' => $via])]), $client);
    }

    /** @param string|null $via the AI client token name for MCP writes (ADR-036) */
    public function setStatus(User $user, Project $project, string $publicId, string $status, ClientInfo $client, ?string $via = null): void
    {
        $this->authorize($user, $project);
        if (!in_array($status, self::USER_STATUSES, true)) {
            throw new HttpException(400, 'Unknown status.');
        }
        $opportunity = $this->opportunities->find($project->id, $publicId) ?? throw new NotFoundHttpException();
        $this->opportunities->setStatus($project->id, $publicId, $status, $user->id);
        if ($status === StoredOpportunity::DONE && $opportunity->status !== StoredOpportunity::DONE) {
            $this->outcomes->completed($project, $opportunity, null, 'finding');
        }
        $this->audit->record(new AuditEvent('opportunities.status_changed', $via === null ? ActorType::User : ActorType::Mcp, actorUserId: $user->id, actorLabel: $via ?? $user->email, workspaceId: $project->workspaceId, targetType: 'opportunity', targetId: $publicId, metadata: [
            'from' => $opportunity->status,
            'to' => $status,
            'type' => $opportunity->type->value,
            ...($via === null ? [] : ['via' => $via]),
        ]), $client);
    }

    /**
     * Recalculates now (local data only, a few queries).
     *
     * @return array{opened: int, updated: int, resolved: int}
     */
    public function analyzeNow(User $user, Project $project): array
    {
        $this->authorize($user, $project);
        $this->features->require(Feature::Opportunities);
        if (!$this->rateLimiter->hit('opportunities:analyze:project:' . $project->id, 6, 600)->allowed) {
            throw new HttpException(429, 'The analysis ran several times in the last minutes. Please try again later.');
        }

        return $this->analyzer->analyze($project->id, $project->workspaceId) ?? throw new NotFoundHttpException();
    }

    private function authorize(User $user, Project $project): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change opportunities of this project.');
        }
    }
}
