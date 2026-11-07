<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\DateRange;

/** Read methods require a project authorized by ProjectService or MCP ToolContext. */
final readonly class AnnotationService
{
    public const string CONTEXT = 'Manually recorded changes provide context, not proof of causation. Search engines may take time to reflect changes; date boundaries can differ between data sources.';
    public function __construct(private AnnotationRepository $annotations, private Authorizer $authorizer, private AuditLogger $audit, private Clock $clock, private ?AnnotationObservers $observers = null) {}

    /** @return array{items: list<Annotation>, total: int, page: int, pages: int} */
    public function listing(Project $project, ?DateRange $range = null, ?string $url = null, int $page = 1): array
    {
        $total = $this->annotations->count($project, $range, $url);
        $pages = max(1, (int) ceil($total / 25));
        $page = max(1, min($pages, $page));
        return ['items' => $this->annotations->list($project, $range, $url, 25, ($page - 1) * 25), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Counters for the timeline header: all changes, changes per impact phase
     * among the last ImpactWindow::SETTLED_DAYS days (at most 100 read), the
     * last 30 days and recent changes per type.
     *
     * @return array{total: int, last30: int, phases: array<string, int>, kinds: array<string, int>, truncated: bool}
     */
    public function overview(Project $project): array
    {
        $today = $this->clock->now()->format('Y-m-d');
        $recentRange = DateRange::endingOn($today, ImpactWindow::SETTLED_DAYS);
        $total = $this->annotations->count($project, null, null);
        $recentCount = $this->annotations->count($project, $recentRange, null);
        $phases = [ImpactWindow::WAITING => 0, ImpactWindow::SIGNAL => 0, ImpactWindow::READY => 0, ImpactWindow::SETTLED => $total - $recentCount];
        $kinds = [];
        foreach ($this->annotations->list($project, $recentRange, null, 100, 0) as $annotation) {
            $phases[(new ImpactWindow($annotation->kind, $annotation->date, $today))->phase]++;
            $kinds[$annotation->kind] = ($kinds[$annotation->kind] ?? 0) + 1;
        }
        arsort($kinds);

        return ['total' => $total, 'last30' => $this->annotations->count($project, DateRange::endingOn($today, 30), null), 'phases' => $phases, 'kinds' => $kinds, 'truncated' => $recentCount > 100];
    }

    /** @return list<Annotation> Most recent 100 within the displayed range. */
    public function context(Project $project, DateRange $range, ?string $url = null): array
    {
        return $this->annotations->list($project, $range, $url, 100, 0);
    }

    public function get(Project $project, string $publicId): Annotation
    {
        return $this->annotations->find($project, $publicId) ?? throw new NotFoundHttpException('Annotation not found.');
    }

    /**
     * @param array<string, mixed> $data
     * @param int|null $expectedRevision update only this revision (MCP writes); null overwrites, as the browser form does
     * @param string|null $via the AI client token name for MCP writes (ADR-036)
     */
    public function save(User $user, Project $project, array $data, ClientInfo $client, ?string $publicId = null, ?int $expectedRevision = null, ?string $via = null): Annotation
    {
        if ($publicId !== null) {
            $this->get($project, $publicId);
        }
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $result = $this->annotations->save($project, AnnotationInput::fromArray($data, $project, $this->clock), $user->id, $publicId, $expectedRevision, $via);
        $this->record($user, $project, $result->publicId, $publicId === null ? 'created' : 'updated', $client, $via);
        if ($publicId === null) {
            $this->observers?->recorded($project, $result);
        }
        return $result;
    }

    public function remove(User $user, Project $project, string $publicId, ClientInfo $client): void
    {
        $this->get($project, $publicId);
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->annotations->remove($project, $publicId);
        $this->record($user, $project, $publicId, 'removed', $client);
    }

    private function record(User $user, Project $project, string $publicId, string $action, ClientInfo $client, ?string $via = null): void
    {
        $this->audit->record(new AuditEvent('project.annotation_' . $action, $via === null ? ActorType::User : ActorType::Mcp, actorUserId: $user->id, actorLabel: $via, workspaceId: $project->workspaceId, targetType: 'seo_annotation', targetId: $publicId, metadata: $via === null ? [] : ['via' => $via]), $client);
    }
}
