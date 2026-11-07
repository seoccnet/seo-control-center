<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Clusters;

use SeoControlCenter\Domain\Projects\Project;

/**
 * Saved clusters per project, scoped to the project's workspace.
 */
interface ClusterRepository
{
    /**
     * Active clusters first by demand, then retired ones.
     *
     * @return list<KeywordCluster>
     */
    public function forProject(Project $project, bool $withRetired = true): array;

    public function find(Project $project, string $publicId): ?KeywordCluster;

    /**
     * Keywords of every saved cluster, for matching IDs on the next refresh.
     *
     * @return array<string, list<string>> public ID => keywords
     */
    public function previous(int $projectId): array;

    /**
     * Replaces the computed part of the project's clusters in one step. Each
     * row keeps its public ID (matched by the caller) and any manual owner;
     * clusters not in $rows are deleted, or retired when they have a manual
     * owner.
     *
     * @param list<array{public_id: string, label: string, size: int, demand: float, members: list<array{keyword: string, demand: float}>, status: string, owner_url: ?string, owner_source: ?string, inferred_url: ?string, owner_reason: string, candidates: list<array<string, mixed>>, overlap: list<array<string, mixed>>, overlap_flagged: int}> $rows
     */
    public function sync(int $projectId, int $workspaceId, array $rows, string $now): void;

    /**
     * Sets or clears ($url null) the manual owner at the expected revision.
     * Returns false on a revision mismatch.
     */
    public function setManualOwner(Project $project, string $publicId, ?string $url, int $userId, ?string $via, int $expectedRevision, string $now): bool;
}
