<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteMigration;

/**
 * Site migration plans and their addresses (1.2.0). Every read takes the
 * project; callers authorize the project first.
 */
interface SiteMigrationRepository
{
    public function create(int $projectId, int $workspaceId, string $name, string $oldOrigin, string $newOrigin, ?string $launchDate, int $userId): SiteMigration;

    public function find(int $projectId, string $publicId): ?SiteMigration;

    public function byId(int $id): ?SiteMigration;

    /** @return list<SiteMigration> newest first */
    public function list(int $projectId): array;

    public function update(int $id, string $name, string $oldOrigin, string $newOrigin, ?string $launchDate): void;

    public function delete(int $id): void;

    /**
     * Adds old addresses not in the plan yet, until it holds $max; known
     * addresses keep their row but take a click count when one is given.
     *
     * @param list<array{url: string, clicks: ?int}> $urls
     * @return int addresses added
     */
    public function addUrls(int $migrationId, array $urls, int $max): int;

    /**
     * Replaces the new site's addresses.
     *
     * @param list<string> $urls
     */
    public function replaceTargets(int $migrationId, array $urls): void;

    /** @return list<string> */
    public function targets(int $migrationId): array;

    /** Removes every old address. */
    public function clearUrls(int $migrationId): void;

    /**
     * @param ?string $filter a test state, "problems", "unmatched", or null for all
     * @return list<MigrationUrl> by old clicks (most first), then address
     */
    public function urls(int $migrationId, ?string $filter, int $offset, int $limit): array;

    public function countUrls(int $migrationId, ?string $filter): int;

    /** @return list<MigrationUrl> in ID order, after $afterId */
    public function urlsAfter(int $migrationId, int $afterId, int $limit): array;

    /**
     * Stores suggestions; rows without a manual choice take the suggestion as their target.
     *
     * @param array<int, array{url: string, method: string, confidence: int}|null> $suggestions URL row ID => suggestion
     */
    public function saveSuggestions(int $migrationId, array $suggestions): void;

    /** Sets the chosen target by hand (null clears it); false when the address is not in the plan. */
    public function setTarget(int $migrationId, string $key, ?string $targetUrl): bool;

    public function saveTest(int $migrationId, int $urlId, string $state, string $detail, string $testedAt): void;

    /** @return array<string, int> test state => addresses */
    public function stateCounts(int $migrationId): array;

    /**
     * @param 'match'|'test' $task
     */
    public function setState(int $id, string $task, string $state, ?string $error = null): void;

    public function saveTestCursor(int $id, int $cursor, ?string $testedAt = null): void;
}
