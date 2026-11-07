<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ChangeBundles;

use SeoControlCenter\Domain\Projects\Project;

/**
 * Change bundles (ADR-042, 0.40.0), always read and written through their
 * project. `data` holds the reviewed content and state (see ChangeBundleService).
 *
 * @phpstan-type Bundle array{public_id: string, title: string, url: string, status: string, version: int, created_at: string, updated_at: string, data: array<string, mixed>}
 */
interface ChangeBundleRepository
{
    /** @return list<Bundle> newest first */
    public function list(Project $project, int $limit): array;

    /**
     * @return Bundle|null
     * @phpstan-impure
     */
    public function find(Project $project, string $publicId): ?array;

    public function countInProject(int $projectId): int;

    /** @param array<string, mixed> $data */
    public function create(Project $project, string $publicId, string $title, string $url, string $status, array $data, ?int $userId, string $now): void;

    /**
     * Replaces the bundle's state when it is still at that version.
     *
     * @param array<string, mixed> $data
     */
    public function update(Project $project, string $publicId, string $title, string $url, string $status, array $data, int $expectedVersion, string $now): bool;

    /** Deletes the bundle and its history when it is still at that version. */
    public function delete(Project $project, string $publicId, int $expectedVersion): bool;

    public function addEvent(Project $project, string $publicId, string $kind, string $detail, ?int $userId, string $now): void;

    /** @return list<array{kind: string, detail: string, created_at: string}> newest first, at most 100 */
    public function events(Project $project, string $publicId): array;
}
