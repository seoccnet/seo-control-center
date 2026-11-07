<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

use SeoControlCenter\Domain\Projects\Project;

/**
 * Stored analyses, scoped to the project's workspace.
 */
interface AnalysisRepository
{
    /**
     * @param list<string> $keywords
     * @return string public ID
     */
    public function queue(Project $project, string $clusterId, string $label, string $keyword, array $keywords, float $demand, string $serpSource, int $locationCode, string $languageCode, int $userId, string $now): string;

    public function find(Project $project, string $publicId): ?Analysis;

    /** For the job: the analysis by ID alone, with its project and workspace. */
    public function byId(string $publicId): ?Analysis;

    public function latestForCluster(Project $project, string $clusterId): ?Analysis;

    /** Marks a queued analysis running; false when it is not queued any more. */
    public function start(string $publicId, string $now): bool;

    /**
     * @param list<array<string, mixed>> $results
     * @param array<string, array<string, mixed>> $pages
     * @param array<string, mixed> $verdict
     */
    public function complete(string $publicId, array $results, ?string $ownUrl, ?int $ownPosition, array $pages, array $verdict, ?float $cost, string $now): void;

    public function fail(string $publicId, string $error, string $now): void;

    /**
     * The newest finished analysis of each topic that finished on or after $since.
     *
     * @return list<Analysis>
     */
    public function latestDone(int $projectId, string $since): array;
}
