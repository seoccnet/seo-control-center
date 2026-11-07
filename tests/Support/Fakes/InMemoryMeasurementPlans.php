<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Modules\Annotations\MeasurementPlanRepository;

/** @phpstan-import-type Plan from MeasurementPlanRepository */
final class InMemoryMeasurementPlans implements MeasurementPlanRepository
{
    /** @var array<int, array<string, Plan>> by project ID and change ID */
    public array $plans = [];

    public function __construct(private readonly AnnotationRepository $annotations) {}

    public function plannedChanges(Project $project, int $limit): array
    {
        $changes = [];
        foreach (array_keys($this->plans[$project->id] ?? []) as $id) {
            $annotation = $this->annotations->find($project, (string) $id);
            if ($annotation !== null) {
                $changes[] = $annotation;
            }
        }
        usort($changes, static fn($a, $b): int => [$b->date, $b->createdAt] <=> [$a->date, $a->createdAt]);

        return array_slice(array_map(static fn($a): string => $a->publicId, $changes), 0, $limit);
    }

    public function find(Project $project, string $annotationId): ?array
    {
        return $this->annotations->find($project, $annotationId) === null ? null : ($this->plans[$project->id][$annotationId] ?? null);
    }

    public function save(Project $project, string $annotationId, string $publicId, array $fields, int $expectedVersion, bool $clearBaseline, ?int $userId, string $now): bool
    {
        if ($this->annotations->find($project, $annotationId) === null) {
            return false;
        }
        $old = $this->plans[$project->id][$annotationId] ?? null;
        if (($old['version'] ?? 0) !== $expectedVersion) {
            return false;
        }
        $this->plans[$project->id][$annotationId] = [
            'public_id' => $old['public_id'] ?? $publicId,
            'annotation_id' => $annotationId,
            ...$fields,
            'baseline' => $clearBaseline || $old === null ? null : $old['baseline'],
            'frozen_at' => $clearBaseline || $old === null ? null : $old['frozen_at'],
            'version' => $expectedVersion + 1,
            'updated_at' => $now,
        ];

        return true;
    }

    public function freeze(Project $project, string $annotationId, array $baseline, int $expectedVersion, ?int $userId, string $now): bool
    {
        $old = $this->find($project, $annotationId);
        if ($old === null || $old['version'] !== $expectedVersion) {
            return false;
        }
        $this->plans[$project->id][$annotationId] = [...$old, 'baseline' => $baseline, 'frozen_at' => $now, 'version' => $expectedVersion + 1, 'updated_at' => $now];

        return true;
    }
}
