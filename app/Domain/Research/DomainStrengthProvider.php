<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Research;

use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * Link-based strength (0-100) of many domains in one request: how hard the
 * sites in a results page are to outrank. Paid, on request only.
 */
interface DomainStrengthProvider
{
    public const int MAX_DOMAINS = 1000;

    public function isAvailable(int $workspaceId): bool;

    public function estimatedCost(int $domains): float;

    /**
     * @param list<string> $domains bare domains, at most MAX_DOMAINS
     * @return array<string, int> domain => strength 0-100; domains the provider does not know are left out
     * @throws IntegrationException
     */
    public function strengths(int $workspaceId, ?int $projectId, array $domains): array;
}
