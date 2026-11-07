<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Keywords;

/**
 * Requests a background metrics update for a project's keywords.
 */
interface KeywordMetricsScheduler
{
    public function requestMetrics(int $projectId, int $workspaceId): void;
}
