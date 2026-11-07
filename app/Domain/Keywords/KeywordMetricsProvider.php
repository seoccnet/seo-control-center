<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Keywords;

use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * Source of keyword search volume, CPC and competition (e.g. DataForSEO).
 * Calls may cost money; implementations enforce the workspace budget.
 */
interface KeywordMetricsProvider
{
    /**
     * Whether the workspace has a working account for this provider.
     */
    public function isAvailable(int $workspaceId): bool;

    public function maxKeywordsPerRequest(): int;

    /**
     * @param list<string> $keywords normalized, at most maxKeywordsPerRequest()
     * @return list<KeywordMetrics> keywords without data may be missing
     * @throws BudgetExceededException
     * @throws IntegrationException
     */
    public function fetch(int $workspaceId, ?int $projectId, array $keywords, KeywordLocale $locale): array;
}
