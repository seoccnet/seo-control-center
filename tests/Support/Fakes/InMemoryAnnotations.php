<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Annotations\Annotation;
use SeoControlCenter\Modules\Annotations\AnnotationInput;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;

final class InMemoryAnnotations implements AnnotationRepository
{
    /** @var array<string, array{project: int, workspace: int, value: Annotation}> */
    private array $rows = [];
    public function __construct(private readonly Clock $clock) {}
    public function list(Project $project, ?DateRange $range, ?string $url, int $limit, int $offset): array
    {
        $rows = array_values(array_filter($this->rows, static fn(array $r): bool => $r['project'] === $project->id && $r['workspace'] === $project->workspaceId && ($range === null || $range->contains($r['value']->date)) && ($url === null || $r['value']->url === null || $r['value']->url === $url)));
        usort($rows, static fn(array $a, array $b): int => [$b['value']->date, $b['value']->publicId] <=> [$a['value']->date, $a['value']->publicId]);
        return array_map(static fn(array $r): Annotation => $r['value'], array_slice($rows, max(0, $offset), max(1, min(100, $limit))));
    }
    public function count(Project $project, ?DateRange $range, ?string $url): int
    {
        return count(array_filter($this->rows, static fn(array $r): bool => $r['project'] === $project->id && $r['workspace'] === $project->workspaceId && ($range === null || $range->contains($r['value']->date)) && ($url === null || $r['value']->url === null || $r['value']->url === $url)));
    }
    public function find(Project $project, string $publicId): ?Annotation
    {
        $row = $this->rows[$publicId] ?? null;
        return $row !== null && $row['project'] === $project->id && $row['workspace'] === $project->workspaceId ? $row['value'] : null;
    }
    public function save(Project $project, AnnotationInput $input, int $userId, ?string $publicId, ?int $expectedRevision = null, ?string $via = null): Annotation
    {
        $existing = $publicId === null ? null : $this->find($project, $publicId);
        if ($publicId !== null && $existing === null) {
            throw new \RuntimeException('Annotation not found.');
        }
        if ($existing !== null && $expectedRevision !== null && $existing->revision !== $expectedRevision) {
            throw new \SeoControlCenter\Core\Http\Exception\HttpException(409, 'This change note was changed in the meantime. Read it again before saving.');
        }
        $publicId ??= Uuid::v7();
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $createdAt = $existing === null ? $now : $existing->createdAt;
        $value = new Annotation($publicId, $input->date, $input->kind, $input->title, $input->note, $input->url, $createdAt, $now, $existing === null ? 1 : $existing->revision + 1, $via);
        $this->rows[$publicId] = ['project' => $project->id, 'workspace' => $project->workspaceId, 'value' => $value];
        return $value;
    }
    public function remove(Project $project, string $publicId): void
    {
        if ($this->find($project, $publicId) !== null) {
            unset($this->rows[$publicId]);
        }
    }
}
