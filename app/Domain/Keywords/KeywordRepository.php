<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Keywords;

/**
 * Project keywords and keyword metrics. Metrics are stored per workspace
 * (they were bought with that workspace's provider account) and shared by
 * the workspace's projects. Every method takes the project/workspace
 * context; callers must have authorized access to the project.
 */
interface KeywordRepository
{
    /**
     * Adds keywords that the project does not track yet for this locale.
     *
     * @param list<string> $keywords normalized
     * @return int number of keywords added
     */
    public function add(int $projectId, array $keywords, KeywordLocale $locale, int $userId): int;

    public function remove(int $projectId, string $publicId): bool;

    public function count(int $projectId): int;

    public function find(int $projectId, int $workspaceId, string $publicId): ?ProjectKeyword;

    /**
     * All keyword texts the project tracks (any market).
     *
     * @return list<string>
     */
    public function texts(int $projectId): array;

    /**
     * @return list<ProjectKeyword> sorted by search volume (unknown last), then keyword
     */
    public function page(int $projectId, int $workspaceId, int $limit, int $offset): array;

    /**
     * The locale of the most recently added keyword, used as form default.
     */
    public function lastLocale(int $projectId): ?KeywordLocale;

    /**
     * The distinct markets of the project's tracked keywords, the market with
     * the most keywords first (ties: the market added first). Unsupported
     * stored markets are left out.
     *
     * @return list<KeywordLocale>
     */
    public function marketsByCount(int $projectId, int $limit): array;

    /**
     * Keywords of the project without metrics, or with metrics fetched before $staleBefore.
     * Returns nothing when the project was deleted or is not in the workspace,
     * so a queued job never spends money on it.
     *
     * @return list<array{locale: KeywordLocale, keywords: list<string>}> grouped by locale
     */
    public function needingMetrics(int $projectId, int $workspaceId, \DateTimeImmutable $staleBefore, int $limit): array;

    /**
     * Stores the latest metrics (replacing older ones) for the workspace.
     *
     * @param list<KeywordMetrics> $metrics
     */
    public function storeMetrics(int $workspaceId, KeywordLocale $locale, array $metrics, \DateTimeImmutable $fetchedAt): void;

    public function syncState(int $projectId): ?KeywordSyncState;

    public function setSyncState(int $projectId, string $status, ?string $error = null): void;
}
