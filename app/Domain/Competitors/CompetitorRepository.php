<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Competitors;

use SeoControlCenter\Domain\Rankings\RankingDay;
use SeoControlCenter\Domain\Rankings\SerpDevice;

/**
 * Competitors of a project and their rankings for the project's keywords.
 * Callers must have authorized access to the project.
 */
interface CompetitorRepository
{
    /**
     * @return list<Competitor> by domain
     */
    public function forProject(int $projectId): array;

    public function find(int $projectId, string $publicId): ?Competitor;

    /**
     * @return Competitor|null null when the domain is already a competitor of the project
     */
    public function add(int $projectId, string $domain, string $source, int $userId): ?Competitor;

    public function remove(int $projectId, string $publicId): bool;

    /**
     * Stores competitor positions for one keyword and day (replacing a re-check).
     *
     * @param list<array{competitor_id: int, position: ?int, url: ?string}> $positions
     */
    public function recordPositions(int $projectKeywordId, SerpDevice $device, string $date, array $positions): void;

    /**
     * Recomputes the competitors' daily aggregates for a day.
     */
    public function aggregate(int $projectId, int $workspaceId, SerpDevice $device, string $date): void;

    /**
     * Fills a new competitor's positions for the given check days from the top 10
     * results stored with the project's own snapshots (positions beyond 10 are
     * unknown there and count as not ranking until the next check).
     *
     * @param list<string> $dates
     * @return list<string> days that had snapshots
     */
    public function backfill(Competitor $competitor, SerpDevice $device, array $dates): array;

    /**
     * @return array<int, RankingDay> competitor ID => aggregate of that exact day
     */
    public function days(int $projectId, SerpDevice $device, string $date): array;

    /**
     * The project's and the competitor's positions per keyword on a day.
     *
     * @return list<KeywordComparison>
     */
    public function compare(int $projectId, int $workspaceId, int $competitorId, SerpDevice $device, string $date): array;

    /**
     * Domains in the top 10 of the project's keywords on a day, most frequent first,
     * excluding the project's own domain and existing competitors.
     *
     * @return list<CompetitorCandidate>
     */
    public function candidates(int $projectId, SerpDevice $device, string $date, string $ownDomain, int $limit): array;
}
