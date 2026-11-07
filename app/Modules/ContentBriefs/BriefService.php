<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentBriefs;

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
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Annotations\ImpactWindow;

/** Reads require a project authorized by ProjectService or ToolContext. */
final readonly class BriefService
{
    /**
     * @param (\Closure(Project, ContentBrief): void)|null $onSaved told about every saved brief (article ideas track published ones, ADR-041 G3)
     */
    public function __construct(private BriefRepository $briefs, private BriefBuilder $builder, private Authorizer $authorizer, private AuditLogger $audit, private Clock $clock, private ?\Closure $onSaved = null) {}

    /** @return array<string, int> briefs per status, all statuses present */
    public function statusCounts(Project $project): array
    {
        $counts = [];
        foreach (array_keys(BriefInput::STATUSES) as $status) {
            $counts[$status] = $this->briefs->count($project, $status, '');
        }
        return $counts;
    }

    /**
     * Progress of a brief: days in the writing queue (since it was created)
     * and, once published, the countdown until the change can show in
     * search data. A brief without a URL is a new page, which takes longer.
     *
     * @return array{queue_days: int, window: ?ImpactWindow}
     */
    public function progress(ContentBrief $brief): array
    {
        $today = $this->clock->now()->format('Y-m-d');
        $created = new \DateTimeImmutable(substr($brief->createdAt, 0, 10));
        $published = $brief->input->status === 'published' && $brief->publishedAt !== null ? substr($brief->publishedAt, 0, 10) : null;
        return [
            'queue_days' => max(0, (int) $created->diff(new \DateTimeImmutable($published ?? $today))->format('%r%a')),
            'window' => $published !== null ? new ImpactWindow($brief->input->url === null ? 'new_page' : 'content', $published, $today) : null,
        ];
    }
    /** @return array{items: list<ContentBrief>, total: int, page: int, pages: int, status: string, search: string} */
    public function listing(Project $project, string $status = '', string $search = '', int $page = 1): array
    {
        $status = isset(BriefInput::STATUSES[$status]) ? $status : '';
        $search = mb_substr(trim($search), 0, 200);
        $total = $this->briefs->count($project, $status, $search);
        $pages = max(1, (int) ceil($total / 25));
        $page = max(1, min($pages, $page));
        return ['items' => $this->briefs->list($project, $status, $search, 25, ($page - 1) * 25), 'total' => $total, 'page' => $page, 'pages' => $pages, 'status' => $status, 'search' => $search];
    }
    public function get(Project $project, string $id): ContentBrief
    {
        return $this->briefs->find($project, $id) ?? throw new NotFoundHttpException('Content brief not found.');
    }
    public function authorizeEdit(User $user, Project $project): void
    {
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
    }
    /**
     * @param array<string, mixed> $data
     * @param string|null $via the AI client token name for MCP writes (ADR-036)
     */
    public function save(User $user, Project $project, array $data, ClientInfo $client, ?string $id = null, ?string $via = null): ContentBrief
    {
        if ($id !== null) {
            $this->get($project, $id);
        }
        $this->authorizeEdit($user, $project);
        $input = BriefInput::fromArray($data, $project);
        if ($id === null) {
            $source = $this->builder->build($project, $input->keyword, $input->url ?? '', self::text($data, 'keyword_id'), self::text($data, 'opportunity_id'), inferUrl: false);
            $brief = $this->briefs->create($project, $input, $source['evidence'], $user->id, $via);
        } else {
            if (!$this->briefs->update($project, $id, $input, self::revision($data), $via)) {
                throw new HttpException(409, 'This brief changed in another tab. Your text has been kept below. Open the latest version in another tab and compare before saving.');
            }
            $brief = $this->get($project, $id);
        }
        $this->record($user, $project, $brief->publicId, $id === null ? 'created' : 'updated', $client, $via);
        if ($this->onSaved !== null) {
            ($this->onSaved)($project, $brief);
        }
        return $brief;
    }
    /** @param array<string, mixed> $data */
    public function remove(User $user, Project $project, string $id, array $data, ClientInfo $client): void
    {
        $this->get($project, $id);
        $this->authorizeEdit($user, $project);
        if (!$this->briefs->remove($project, $id, self::revision($data))) {
            throw new HttpException(409, 'This brief changed. Reload it before removing.');
        }
        $this->record($user, $project, $id, 'removed', $client);
    }
    /** @param array<string, mixed> $data */
    public static function text(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? trim($data[$key]) : '';
    }
    /** @param array<string, mixed> $data */
    private static function revision(array $data): int
    {
        $raw = self::text($data, 'revision');
        return ctype_digit($raw) && strlen($raw) <= 9 ? (int) $raw : 0;
    }
    private function record(User $user, Project $project, string $id, string $action, ClientInfo $client, ?string $via = null): void
    {
        $this->audit->record(new AuditEvent('project.brief_' . $action, $via === null ? ActorType::User : ActorType::Mcp, actorUserId: $user->id, actorLabel: $via, workspaceId: $project->workspaceId, targetType: 'content_brief', targetId: $id, metadata: $via === null ? [] : ['via' => $via]), $client);
    }
    public function export(ContentBrief $brief): string
    {
        $lines = [$brief->input->title, 'Target keyword: ' . $brief->input->keyword, 'URL: ' . ($brief->input->url ?? 'New page'), 'Status: ' . BriefInput::STATUSES[$brief->input->status], 'Updated ' . $brief->updatedAt . ' UTC', '', BriefBuilder::NOTICE];
        foreach (BriefInput::FIELDS as $key => $label) {
            array_push($lines, '', $label, $brief->input->sections[$key] ?? '');
        }
        array_push($lines, '', 'Original evidence snapshot', ...$brief->evidence);
        return implode("\n", $lines) . "\n";
    }
}
