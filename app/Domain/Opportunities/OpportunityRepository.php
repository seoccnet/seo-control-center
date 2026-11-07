<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * Stored opportunities per project. Callers must have authorized access
 * to the project.
 */
interface OpportunityRepository
{
    /**
     * Replaces the project's detected set: new ones are opened, known ones
     * updated (resolved ones reopened), open ones no longer detected are
     * resolved. Done and dismissed opportunities keep their status.
     *
     * @param list<string>|null $coveredTypes null is legacy complete coverage; [] preserves every type
     * @param array<string, array{status: string, date: ?string, notice?: string}> $coverage
     * @param list<Opportunity> $detected
     * @return array{opened: int, updated: int, resolved: int}
     */
    public function sync(int $projectId, array $detected, ?array $coveredTypes = null, array $coverage = []): array;

    /**
     * @return list<StoredOpportunity> by priority, highest first
     */
    public function list(int $projectId, string $status, ?OpportunityType $type, int $limit, int $offset): array;

    /** @return array{rows: list<StoredOpportunity>, total: int} bounded, all statuses for consolidation */
    public function catalog(int $projectId): array;

    public function count(int $projectId, string $status, ?OpportunityType $type = null): int;

    /**
     * @return array<string, int> open opportunities per type value
     */
    public function openByType(int $projectId): array;

    public function find(int $projectId, string $publicId): ?StoredOpportunity;

    /**
     * Changes the status set by a user and records it in the history.
     */
    public function setStatus(int $projectId, string $publicId, string $status, int $userId): bool;

    public function snooze(int $projectId, string $publicId, string $until, int $userId): bool;

    /** @return array<string, array{status: string, date: ?string, notice?: string}> */
    public function coverage(int $projectId): array;

    public function lastAnalyzedAt(int $projectId): ?string;

    /**
     * Projects that are not deleted, for the scheduled analysis.
     *
     * @return list<array{project_id: int, workspace_id: int}>
     */
    public function projectsToAnalyze(): array;
}
