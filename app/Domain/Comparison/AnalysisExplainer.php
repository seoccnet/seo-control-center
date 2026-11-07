<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

/**
 * The AI explanation of a finished deterministic analysis (ADR-038, D2):
 * one model call with the structured facts, validated, retried once on
 * invalid output. It explains and prioritizes; it never adds facts.
 */
interface AnalysisExplainer
{
    public function available(int $workspaceId): bool;

    /** USD for one explanation, or null when no AI is set up. */
    public function estimatedCost(int $workspaceId): ?float;

    /**
     * @param array<string, mixed> $facts from Explanation::facts()
     * @return array{status: string, note: ?string, explanation: ?array<string, mixed>, cost: float}
     *               status: done, unavailable, budget, failed or invalid
     */
    public function explain(int $workspaceId, array $facts): array;
}
