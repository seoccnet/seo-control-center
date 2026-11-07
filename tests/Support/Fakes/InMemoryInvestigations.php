<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Investigations\Investigation;
use SeoControlCenter\Domain\Investigations\InvestigationItem;
use SeoControlCenter\Domain\Investigations\InvestigationRepository;
use SeoControlCenter\Domain\Projects\Project;

/**
 * Investigations in memory, scoped by workspace and project like the PDO
 * repository.
 */
final class InMemoryInvestigations implements InvestigationRepository
{
    /** @var array<string, array{workspace_id: int, project_id: int, title: string, question: ?string, status: string, conclusion: ?string, via: ?string, revision: int, created_at: string, updated_at: string, items: list<InvestigationItem>}> */
    public array $rows = [];

    public function __construct(private readonly Clock $clock) {}

    public function list(Project $project, ?string $status, int $limit, int $offset): array
    {
        $rows = array_filter($this->rows, static fn(array $r): bool => $r['workspace_id'] === $project->workspaceId && $r['project_id'] === $project->id && ($status === null || $r['status'] === $status));
        uksort($rows, static fn(string $a, string $b): int => [$rows[$b]['updated_at'], $b] <=> [$rows[$a]['updated_at'], $a]);
        $list = [];
        foreach (array_slice($rows, $offset, $limit, true) as $id => $r) {
            $list[] = $this->hydrate((string) $id, $r, false);
        }

        return $list;
    }

    public function count(Project $project, ?string $status): int
    {
        return count(array_filter($this->rows, static fn(array $r): bool => $r['workspace_id'] === $project->workspaceId && $r['project_id'] === $project->id && ($status === null || $r['status'] === $status)));
    }

    public function find(Project $project, string $publicId): ?Investigation
    {
        $r = $this->rows[$publicId] ?? null;

        return $r !== null && $r['workspace_id'] === $project->workspaceId && $r['project_id'] === $project->id ? $this->hydrate($publicId, $r, true) : null;
    }

    public function create(Project $project, array $fields, ?int $userId, ?string $via): string
    {
        $id = Uuid::v7($this->clock);
        $now = $this->now();
        $this->rows[$id] = ['workspace_id' => $project->workspaceId, 'project_id' => $project->id, ...$fields, 'via' => $via, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now, 'items' => []];

        return $id;
    }

    public function update(Project $project, string $publicId, array $fields, ?int $expectedRevision, ?string $via): void
    {
        if ($this->find($project, $publicId) === null) {
            throw new HttpException(404, 'Investigation not found.');
        }
        if ($expectedRevision !== null && $this->rows[$publicId]['revision'] !== $expectedRevision) {
            throw new HttpException(409, 'This investigation was changed in the meantime. Read it again before saving.');
        }
        $this->rows[$publicId] = [...$this->rows[$publicId], ...$fields, 'via' => $via, 'revision' => $this->rows[$publicId]['revision'] + 1, 'updated_at' => $this->now()];
    }

    public function addItems(Project $project, string $publicId, array $items, ?int $userId, ?string $via): array
    {
        if ($this->find($project, $publicId) === null) {
            throw new HttpException(404, 'Investigation not found.');
        }
        $ids = [];
        $row = $this->rows[$publicId];
        foreach ($items as $item) {
            $id = Uuid::v7($this->clock);
            $row['items'][] = new InvestigationItem($id, $item['kind'], $item['ref_type'], $item['ref_id'], $item['label'], $item['detail'], $item['observed_on'], $via, $this->now());
            $ids[] = $id;
        }
        $row['revision'] = $row['revision'] + 1;
        $row['updated_at'] = $this->now();
        $this->rows[$publicId] = $row;

        return $ids;
    }

    public function removeItem(Project $project, string $publicId, string $itemId): bool
    {
        if ($this->find($project, $publicId) === null) {
            throw new HttpException(404, 'Investigation not found.');
        }
        $row = $this->rows[$publicId];
        $kept = array_values(array_filter($row['items'], static fn(InvestigationItem $i): bool => $i->publicId !== $itemId));
        if (count($kept) === count($row['items'])) {
            return false;
        }
        $row['items'] = $kept;
        $row['revision'] = $row['revision'] + 1;
        $this->rows[$publicId] = $row;

        return true;
    }

    public function itemCount(Project $project, string $publicId): int
    {
        $found = $this->find($project, $publicId);

        return $found === null ? 0 : count($found->items);
    }

    /** @param array{workspace_id: int, project_id: int, title: string, question: ?string, status: string, conclusion: ?string, via: ?string, revision: int, created_at: string, updated_at: string, items: list<InvestigationItem>} $r */
    private function hydrate(string $id, array $r, bool $withItems): Investigation
    {
        $counts = [];
        foreach ($r['items'] as $item) {
            $counts[$item->kind] = ($counts[$item->kind] ?? 0) + 1;
        }

        return new Investigation($id, $r['project_id'], $r['workspace_id'], $r['title'], $r['question'], $r['status'], $r['conclusion'], $r['via'], $r['revision'], $r['created_at'], $r['updated_at'], $withItems ? $r['items'] : [], $counts);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
