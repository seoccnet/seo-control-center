<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Research;

use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;

/**
 * Keyword and domain research from a provider's keyword database
 * (e.g. DataForSEO Labs). Every call costs money; callers run it as a job.
 *
 * @throws BudgetExceededException|IntegrationException from every lookup
 */
interface ResearchProvider
{
    public function isAvailable(int $workspaceId): bool;

    /**
     * Estimated cost in USD of a lookup that returns up to $items rows in $requests requests.
     */
    public function estimatedCost(int $items, int $requests = 1): float;

    /**
     * Keywords the domain ranks for, best traffic first.
     *
     * @return list<ResearchKeyword>
     */
    public function rankedKeywords(int $workspaceId, ?int $projectId, string $domain, KeywordLocale $locale, int $limit): array;

    /**
     * Keywords $competitor ranks for and $domain does not, highest volume first.
     *
     * @return list<ResearchKeyword> position/url/traffic are the competitor's
     */
    public function keywordGap(int $workspaceId, ?int $projectId, string $domain, string $competitor, KeywordLocale $locale, int $limit): array;

    /**
     * Keyword ideas related to the seed keywords, highest volume first.
     *
     * @param list<string> $seeds
     * @return list<ResearchKeyword>
     */
    public function keywordIdeas(int $workspaceId, ?int $projectId, array $seeds, KeywordLocale $locale, int $limit): array;

    /**
     * Longer searches that contain the seed (phrase match), highest volume
     * first. One request per seed.
     *
     * @return list<ResearchKeyword>
     */
    public function keywordSuggestions(int $workspaceId, ?int $projectId, string $seed, KeywordLocale $locale, int $limit): array;

    /**
     * Domains that rank for the most of the same keywords as $domain.
     *
     * @return list<ResearchDomain>
     */
    public function competitors(int $workspaceId, ?int $projectId, string $domain, KeywordLocale $locale, int $limit): array;

    /**
     * Organic overview of each domain (one request per domain); domains the
     * provider rejects are left out.
     *
     * @param list<string> $domains
     * @return list<ResearchDomain>
     */
    public function overview(int $workspaceId, ?int $projectId, array $domains, KeywordLocale $locale): array;
}
