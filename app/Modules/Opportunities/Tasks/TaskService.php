<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Tasks;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Modules\ContentBriefs\BriefRepository;

/** Reads take a project authorized by ProjectService or MCP ToolContext. */
final readonly class TaskService
{
    public function __construct(private TaskRepository $tasks, private OpportunityRepository $opportunities, private WorkspaceRepository $workspaces, private UserRepository $users, private Authorizer $authorizer, private BriefRepository $briefs, private AnnotationRepository $annotations, private Clock $clock, private AuditLogger $audit, private \SeoControlCenter\Modules\Opportunities\OutcomeService $outcomes) {}
    public function opportunity(Project $project, string $id): StoredOpportunity
    {
        return $this->opportunities->find($project->id, $id) ?? throw new NotFoundHttpException();
    }
    public function today(Project $project): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone($project->timezone))->format('Y-m-d');
    }
    /** @return array<string, string> Public member IDs and display names, eligible editors only. */
    public function assignees(Project $project): array
    {
        $this->authorizer->forget();
        $result = [];
        foreach ($this->workspaces->members($project->workspaceId) as $member) {
            $user = $this->users->findById($member->userId);
            if ($member->status === 'active' && $user !== null && $user->active && $this->authorizer->can($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id)) {
                $result[$user->publicId] = $user->name;
            }
        }
        return $result;
    }
    /** @return array{items: list<SeoTask>, total: int, page: int, pages: int, filter: TaskFilter, today: string} */
    public function listing(Project $project, TaskFilter $filter, int $page = 1): array
    {
        $today = $this->today($project);
        $total = $this->tasks->count($project, $filter, $today);
        $pages = max(1, (int) ceil($total / 25));
        $page = max(1, min($page, $pages));
        return ['items' => $this->tasks->list($project, $filter, $today, ($page - 1) * 25), 'total' => $total, 'page' => $page, 'pages' => $pages, 'filter' => $filter, 'today' => $today];
    }
    public function get(Project $project, string $id): ?SeoTask
    {
        $this->opportunity($project, $id);
        return $this->tasks->find($project, $id);
    }
    /** @return array{brief: array<string, string>, annotation: array<string, string>} */
    public function choices(Project $project, TaskInput $input): array
    {
        $briefs = [];
        foreach ($this->briefs->list($project, '', '', 25, 0) as $brief) {
            $briefs[$brief->publicId] = $brief->input->title;
        }
        if ($input->brief !== '' && ($brief = $this->briefs->find($project, $input->brief)) !== null) {
            $briefs[$brief->publicId] = $brief->input->title;
        }
        $annotations = [];
        foreach ($this->annotations->list($project, null, null, 25, 0) as $annotation) {
            $annotations[$annotation->publicId] = $annotation->date . ' · ' . $annotation->title;
        }
        if ($input->annotation !== '' && ($annotation = $this->annotations->find($project, $input->annotation)) !== null) {
            $annotations[$annotation->publicId] = $annotation->date . ' · ' . $annotation->title;
        }
        return ['brief' => $briefs, 'annotation' => $annotations];
    }
    /** @return array{brief: bool, annotation: bool} */
    public function links(Project $project, TaskInput $input): array
    {
        return ['brief' => $input->brief !== '' && $this->briefs->find($project, $input->brief) !== null, 'annotation' => $input->annotation !== '' && $this->annotations->find($project, $input->annotation) !== null];
    }
    public function canEdit(User $user, Project $project): bool
    {
        return $this->authorizer->can($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
    }
    /**
     * @param array<string, mixed> $data
     * @param string|null $via the AI client token name for MCP writes (ADR-036)
     */
    public function save(User $user, Project $project, string $id, array $data, ClientInfo $client, ?string $via = null): void
    {
        $this->opportunity($project, $id);
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $input = TaskInput::fromArray($data, $project);
        if ($input->assignee !== '' && !isset($this->assignees($project)[$input->assignee])) {
            throw new HttpException(422, 'Choose an active team member with edit access to this project.');
        }
        $links = $this->links($project, $input);
        if ($input->brief !== '' && !$links['brief'] || $input->annotation !== '' && !$links['annotation']) {
            throw new HttpException(422, 'Linked records must exist in this project. Removed records cannot be linked.');
        }
        $raw = $data['revision'] ?? null;
        if (!is_string($raw) || !ctype_digit($raw) || strlen($raw) > 9) {
            throw new HttpException(422, 'Missing task revision. Reload the task.');
        }
        $before = $this->tasks->find($project, $id);
        if (!$this->tasks->save($project, $id, $input, (int) $raw, $via)) {
            throw new HttpException(409, 'This task changed in another tab. Your submitted text is kept below. Open the latest version and compare before saving.');
        }
        if ($input->status === 'done' && ($before === null || $before->input->status !== 'done')) {
            $this->outcomes->completed($project, $this->opportunity($project, $id), $input->annotation !== '' ? $input->annotation : null, 'task', $input->url !== '' ? $input->url : null);
        }
        $this->audit->record(new AuditEvent('opportunities.task_saved', $via === null ? ActorType::User : ActorType::Mcp, actorUserId: $user->id, actorLabel: $via, workspaceId: $project->workspaceId, targetType: 'opportunity', targetId: $id, metadata: ['task_status' => $input->status, 'assignee_id' => $input->assignee ?: null, ...($via === null ? [] : ['via' => $via])]), $client);
    }
}
