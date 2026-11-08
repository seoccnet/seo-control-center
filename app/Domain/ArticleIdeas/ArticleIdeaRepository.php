<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ArticleIdeas;

use SeoControlCenter\Domain\Projects\Project;

/**
 * Runs, the competitor rows they bought and the ideas built from them
 * (ADR-041, G1). Project reads are bound to the project's workspace.
 */
interface ArticleIdeaRepository
{
    /**
     * @param list<array{domain: string, found: bool}> $competitors
     * @param list<string> $markets every market the run buys ("location:language"), $market first
     */
    public function createRun(Project $project, array $competitors, ?string $market, float $estimatedCost, ?int $userId, string $now, array $markets = []): ArticleIdeaRun;

    public function run(int $runId): ?ArticleIdeaRun;

    public function latestRun(Project $project): ?ArticleIdeaRun;

    /** The newest finished run of a project, the source of its ideas. */
    public function latestDoneRun(int $projectId): ?ArticleIdeaRun;

    public function hasActiveRun(int $projectId): bool;

    public function markRunning(int $runId): void;

    public function finish(int $runId, string $now): void;

    public function fail(int $runId, string $error, string $now): void;

    /** @param list<array{domain: string, found: bool, url: string, keyword: string, volume: ?int, difficulty: ?int, position: ?int, traffic: ?float, avg_rd: ?int, market?: ?string}> $rows */
    public function saveRows(int $runId, int $projectId, array $rows): void;

    /**
     * The run's rows; market is null on rows saved before markets were stored.
     *
     * @return list<array{domain: string, found: bool, url: string, keyword: string, volume: ?int, difficulty: ?int, position: ?int, traffic: ?float, avg_rd: ?int, market: ?string}>
     */
    public function rows(int $runId): array;

    /**
     * Replaces the project's ideas with those of a build.
     *
     * @param list<ArticleIdea> $ideas
     */
    public function replaceIdeas(int $projectId, int $workspaceId, int $runId, array $ideas, string $now): void;

    /**
     * The project's ideas, best first, without dismissed ones.
     *
     * @return list<ArticleIdea>
     */
    public function ideas(Project $project, int $limit): array;

    public function idea(Project $project, string $key): ?ArticleIdea;

    /** When the project's ideas were last built (UTC), or null. */
    public function builtAt(int $projectId): ?string;

    public function dismiss(Project $project, string $key, string $mainKeyword, ?int $userId, string $now): void;

    /**
     * The newest finished run of every active project (for the daily rebuild).
     *
     * @return list<array{run_id: int, project_id: int, workspace_id: int}>
     */
    public function rebuildable(int $limit): array;

    /** Keeps the rows of the newest $keep finished runs of a project. */
    public function pruneRows(int $projectId, int $keep): void;

    /**
     * Marks an idea's outline as queued. False when it is already queued
     * (and not stale since $staleBefore) or was read since $freshSince.
     */
    public function requestOutline(Project $project, string $key, ?int $userId, string $now, string $freshSince, string $staleBefore): bool;

    public function outline(Project $project, string $key): ?ArticleOutline;

    /**
     * @param list<string> $keys
     * @return array<string, ArticleOutline> by idea key
     */
    public function outlines(Project $project, array $keys): array;

    /** @param list<\SeoControlCenter\Domain\Comparison\PageStructure> $pages */
    public function saveOutline(int $projectId, string $key, array $pages, ?\SeoControlCenter\Domain\Comparison\PageStructure $own, string $now): void;

    public function failOutline(int $projectId, string $key, string $error, string $now): void;
}
