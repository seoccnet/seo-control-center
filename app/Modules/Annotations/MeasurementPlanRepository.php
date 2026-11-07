<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations;

use SeoControlCenter\Domain\Projects\Project;

/**
 * Measurement plans (ADR-042, 0.39.0), one per SEO change, always read and
 * written through the project and the change's public ID.
 *
 * @phpstan-type Plan array{public_id: string, annotation_id: string, objective: string, metric: string, target_pages: list<string>, target_queries: list<string>, control_pages: list<string>, days: int, goal: ?float, review_dates: list<string>, timezone: string, baseline: ?array{targets: array<string, mixed>, controls: ?array<string, mixed>}, frozen_at: ?string, version: int, updated_at: string}
 */
interface MeasurementPlanRepository
{
    /**
     * @return Plan|null
     * @phpstan-impure
     */
    public function find(Project $project, string $annotationId): ?array;

    /**
     * Creates the plan ($expectedVersion 0) or updates that version. A
     * changed setup clears the frozen baseline. False on a version conflict
     * or when the change is not in this project.
     *
     * @param array{objective: string, metric: string, target_pages: list<string>, target_queries: list<string>, control_pages: list<string>, days: int, goal: ?float, review_dates: list<string>, timezone: string} $fields
     */
    public function save(Project $project, string $annotationId, string $publicId, array $fields, int $expectedVersion, bool $clearBaseline, ?int $userId, string $now): bool;

    /**
     * Public IDs of the project's changes that have a plan, newest change first (0.45.0, the results ledger).
     *
     * @return list<string>
     */
    public function plannedChanges(Project $project, int $limit): array;

    /**
     * Freezes the baseline of that version. False on a version conflict.
     *
     * @param array{targets: array<string, mixed>, controls: ?array<string, mixed>} $baseline
     */
    public function freeze(Project $project, string $annotationId, array $baseline, int $expectedVersion, ?int $userId, string $now): bool;
}
