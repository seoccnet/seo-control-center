<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentBriefs;

use SeoControlCenter\Domain\Claims\Claim;

/**
 * Claims of content briefs with their revision history (ADR-042), always
 * scoped by project.
 *
 * @phpstan-type ClaimData array{claim: string, subject: string, claim_scope: array<string, string>, source_url: ?string, publisher: string, checked_on: ?string, passage: string, source_scope: array<string, string>, status: string, note: string}
 */
interface ClaimRepository
{
    /** @return list<Claim> oldest first */
    public function forBrief(int $projectId, string $briefId): array;

    /** @return array{items: list<Claim>, total: int} newest change first */
    public function forProject(int $projectId, string $status, int $offset, int $limit): array;

    public function find(int $projectId, string $publicId): ?Claim;

    public function countForBrief(int $projectId, string $briefId): int;

    /** @param ClaimData $data */
    public function add(int $workspaceId, int $projectId, string $briefId, array $data, ?int $userId): Claim;

    /**
     * Saves a new version when $version is still current, keeping the previous
     * one as a revision. $reviewed records who gave the verdict and when.
     *
     * @param ClaimData $data
     */
    public function update(int $projectId, string $publicId, int $version, array $data, bool $reviewed, ?int $userId): bool;

    /** @return list<array{version: int, snapshot: array<string, mixed>, changed_by: ?int, changed_at: string}> newest first */
    public function revisions(int $projectId, string $publicId): array;

    public function remove(int $projectId, string $publicId): bool;
}
