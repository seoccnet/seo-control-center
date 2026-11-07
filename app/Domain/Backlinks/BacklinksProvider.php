<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Backlinks;

use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * Backlink data from a provider's link index (e.g. DataForSEO Backlinks).
 * Every call costs money; callers run it as a job.
 *
 * @throws BudgetExceededException|IntegrationException from every lookup
 */
interface BacklinksProvider
{
    public function isAvailable(int $workspaceId): bool;

    /**
     * Estimated cost in USD of $requests lookups returning up to $rows rows in total.
     */
    public function estimatedCost(int $requests, int $rows = 0): float;

    /**
     * The link profile of each domain (one request per domain); domains the provider rejects are left out.
     *
     * @param list<string> $domains
     * @return list<BacklinkProfile>
     */
    public function profiles(int $workspaceId, ?int $projectId, array $domains): array;

    /**
     * The link profile of single pages (one request per page, ADR-041 G2); the
     * profile's domain field holds the page URL. Pages the provider rejects
     * are left out.
     *
     * @param list<string> $urls absolute https URLs
     * @return list<BacklinkProfile>
     */
    public function pageProfiles(int $workspaceId, ?int $projectId, array $urls): array;

    /**
     * Domains linking to $domain, best rank first. With $since, only the
     * ones first seen on or after that date; with $lost, the ones whose links
     * were lost (since $since).
     *
     * @return list<ReferringDomain>
     */
    public function referringDomains(int $workspaceId, ?int $projectId, string $domain, int $limit, ?string $since = null, bool $lost = false): array;

    /**
     * @return list<AnchorText> most used first
     */
    public function anchors(int $workspaceId, ?int $projectId, string $domain, int $limit): array;

    /** @return list<PageBacklink> Actual links, with exact source and target URLs. */
    public function pageLinks(int $workspaceId, ?int $projectId, string $domain, int $limit, ?string $since = null, bool $lost = false): array;

    /**
     * Domains that link to the competitors but not to $domain, best rank first.
     *
     * @param list<string> $competitors
     * @return list<ReferringDomain> with `targets` = how many competitors they link to
     */
    public function linkGap(int $workspaceId, ?int $projectId, string $domain, array $competitors, int $limit): array;
}
