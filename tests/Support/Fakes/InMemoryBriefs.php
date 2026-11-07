<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\ContentBriefs\BriefInput;
use SeoControlCenter\Modules\ContentBriefs\BriefRepository;
use SeoControlCenter\Modules\ContentBriefs\ContentBrief;

final class InMemoryBriefs implements BriefRepository
{
    /** @var array<string, array{workspace: int, project: int, value: ContentBrief}> */
    private array $rows = [];
    public function __construct(private readonly Clock $clock) {}
    public function list(Project $project, string $status, string $search, int $limit, int $offset): array
    {
        return array_slice($this->matching($project, $status, $search), $offset, max(1, min(25, $limit)));
    }
    public function count(Project $project, string $status, string $search): int
    {
        return count($this->matching($project, $status, $search));
    }
    /** @return list<ContentBrief> */
    private function matching(Project $project, string $status, string $search): array
    {
        $rows = array_reverse(array_values($this->rows));
        return array_values(array_map(static fn(array $r): ContentBrief => $r['value'], array_filter($rows, static fn(array $r): bool => $r['workspace'] === $project->workspaceId && $r['project'] === $project->id && ($status === '' || $status === $r['value']->input->status) && ($search === '' || mb_stripos($r['value']->input->title . ' ' . $r['value']->input->keyword . ' ' . ($r['value']->input->url ?? ''), $search) !== false))));
    }
    public function find(Project $project, string $publicId): ?ContentBrief
    {
        $row = $this->rows[$publicId] ?? null;
        return $row !== null && $row['workspace'] === $project->workspaceId && $row['project'] === $project->id ? $row['value'] : null;
    }
    public function create(Project $project, BriefInput $input, array $evidence, int $userId, ?string $via = null): ContentBrief
    {
        $id = Uuid::v7();
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $brief = new ContentBrief($id, $input, $evidence, 1, $now, $now, $input->status === 'published' ? $now : null, $via);
        $this->rows[$id] = ['workspace' => $project->workspaceId, 'project' => $project->id, 'value' => $brief];
        return $brief;
    }
    public function update(Project $project, string $publicId, BriefInput $input, int $revision, ?string $via = null): bool
    {
        $old = $this->find($project, $publicId);
        if ($old === null || $old->revision !== $revision) {
            return false;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->rows[$publicId]['value'] = new ContentBrief($publicId, $input, $old->evidence, $revision + 1, $old->createdAt, $now, $input->status === 'published' ? ($old->publishedAt ?? $now) : null, $via);
        return true;
    }
    public function remove(Project $project, string $publicId, int $revision): bool
    {
        if ($this->find($project, $publicId)?->revision !== $revision) {
            return false;
        }
        unset($this->rows[$publicId]);
        return true;
    }
}
