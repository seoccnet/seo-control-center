<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ArticleIdeas;

use SeoControlCenter\Domain\Projects\Project;

/** Published articles from ideas and their measured results (ADR-041, G3), bound to their project's workspace. */
interface ArticleTrackingRepository
{
    /**
     * Starts tracking an idea's article, or replaces the address, date and source of the one it has.
     *
     * @param list<string> $keywords
     * @param array{traffic: array{low: int, expected: int, high: int}, months: array{low: int, high: int, basis: string}, markets?: list<string>} $prediction
     */
    public function track(Project $project, ArticleIdea $idea, array $keywords, array $prediction, string $url, string $publishedOn, string $source, ?int $userId, string $now): ArticleTracking;

    /** @return list<ArticleTracking> newest published first */
    public function forProject(Project $project, int $limit): array;

    public function find(Project $project, string $publicId): ?ArticleTracking;

    /** @return list<ArticleTracking> every project's trackings, oldest checked first */
    public function due(int $limit): array;

    /** @param array{weeks: list<array{week: string, clicks: int, impressions: int, position: ?float}>, top10_week: ?string, best_position: ?float, clicks_28d: int} $result */
    public function saveResult(int $id, array $result, string $now): void;

    public function delete(Project $project, int $id): void;
}
