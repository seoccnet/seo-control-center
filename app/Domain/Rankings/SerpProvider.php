<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;

/**
 * Source of search engine result pages (e.g. DataForSEO SERP API).
 * Foundation for rank tracking (0.4) and competitor discovery (0.5).
 */
interface SerpProvider
{
    public function isAvailable(int $workspaceId): bool;

    /**
     * Estimated cost in USD of one organic() call (for budgets and the UI).
     */
    public function estimatedCost(int $depth): float;

    /**
     * Organic Google results for one keyword.
     *
     * @param int $depth number of results to collect (10-100)
     * @throws BudgetExceededException
     * @throws IntegrationException
     */
    public function organic(int $workspaceId, ?int $projectId, string $keyword, KeywordLocale $locale, SerpDevice $device, int $depth = 100): SerpSnapshot;
}
