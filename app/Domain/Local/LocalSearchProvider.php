<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Local;

use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * Local search data (e.g. DataForSEO's Google Maps SERP and Business Data
 * APIs). Every call costs money; callers run it as a job.
 *
 * @throws BudgetExceededException|IntegrationException from every lookup
 */
interface LocalSearchProvider
{
    public function isAvailable(int $workspaceId): bool;

    /**
     * Estimated cost in USD of a check of $keywords keywords plus one profile lookup.
     */
    public function estimatedCost(int $keywords): float;

    /**
     * Google Maps results for a keyword searched at $area, best first.
     *
     * @return list<LocalResult>
     */
    public function maps(int $workspaceId, ?int $projectId, string $keyword, LocalArea $area, string $language, int $depth): array;

    /**
     * The Business Profile found for $query ("cid:…" or the business name) near $area; null when Google
     * Maps has none (0.46.1: also when DataForSEO answers "No Search Results").
     */
    public function profile(int $workspaceId, ?int $projectId, string $query, LocalArea $area, string $language): ?BusinessProfile;
}
