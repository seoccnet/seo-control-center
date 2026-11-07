<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

/**
 * Background work of "Why they outrank you" (ADR-038): running a queued
 * analysis, and refreshing the project's opportunities after one finished.
 */
interface AnalysisScheduler
{
    public function analyze(string $analysisId, int $workspaceId): void;

    public function refreshOpportunities(int $projectId, int $workspaceId, string $analysisId): void;
}
