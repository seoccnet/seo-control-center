<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

interface OutcomeRepository
{
    /** One completion episode per finding/date; 28/56-day checkpoints are idempotent. $pageUrl is the page the work concerns, when known. */
    public function record(int $projectId, int $workspaceId, StoredOpportunity $opportunity, string $completedOn, ?OutcomeMetrics $baseline, ?string $annotationId, string $source, ?string $pageUrl = null): void;
    /** @return list<OpportunityOutcome> active projects/workspaces only; capped at twenty per pass */
    public function due(string $today, string $retryDate): array;
    public function measured(OpportunityOutcome $row, ?OutcomeMetrics $baseline, ?OutcomeMetrics $actual, string $checkedAt): void;
    /**
     * Calibration per type from 56-day observations of the last year
     * (ADR-039, E2): work marked not implemented is left out, and verified
     * work without overlapping changes is used alone once a type has five.
     *
     * @return array<string, array{factor: float, samples: int}>
     */
    public function factors(int $projectId, string $since): array;
    /** @return list<OpportunityOutcome> newest first, at most fifty */
    public function recent(int $projectId): array;

    public function find(int $projectId, int $workspaceId, string $outcomeId): ?OpportunityOutcome;

    /**
     * Completions not yet verified that name a page, completed on or after
     * $since, one row (the 28-day checkpoint) per completion; active
     * projects only.
     *
     * @return list<OpportunityOutcome>
     */
    public function unverified(string $since, int $limit): array;

    /**
     * Completions of a project between two dates, one row each.
     *
     * @return list<OpportunityOutcome>
     */
    public function completions(int $projectId, string $from, string $to): array;

    /** Sets the implementation state on every checkpoint of the outcome's completion. */
    public function setImplementation(OpportunityOutcome $row, string $state, ?string $source, string $at): void;

    /** @param list<array{kind: string, label: string, date: string, ref: ?string}> $overlaps */
    public function setOverlaps(OpportunityOutcome $row, array $overlaps): void;
}
