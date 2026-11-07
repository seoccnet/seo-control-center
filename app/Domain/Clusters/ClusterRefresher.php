<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Clusters;

use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Saves the clusters an opportunity analysis found (ADR-037). Called after
 * each analysis with the topics it computed; never calls a provider.
 */
interface ClusterRefresher
{
    /**
     * @param array<string, array{label: string, size: int, members: list<string>}> $topics normalized keyword => its topic (topics of two or more keywords)
     * @param array<string, float> $demand normalized keyword => demand (volume or monthly impressions)
     */
    public function refresh(int $projectId, int $workspaceId, string $domain, array $topics, array $demand, ProjectSignals $signals): void;
}
