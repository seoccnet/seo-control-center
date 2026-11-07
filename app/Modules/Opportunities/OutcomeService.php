<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Opportunities\ChangeEvidence;
use SeoControlCenter\Domain\Opportunities\OpportunityConsolidator;
use SeoControlCenter\Domain\Opportunities\OpportunityOutcome;
use SeoControlCenter\Domain\Opportunities\OutcomeMeasurementReader;
use SeoControlCenter\Domain\Opportunities\OutcomeOverlaps;
use SeoControlCenter\Domain\Opportunities\OutcomeRepository;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\DateRange;

final readonly class OutcomeService
{
    /** Completions older than this can no longer gain a change inside the ±14-day window (plus a week of check lag). */
    public const int VERIFY_LOOKBACK_DAYS = 21;
    public const int VERIFY_BATCH = 200;

    public function __construct(private OutcomeRepository $repository, private OutcomeMeasurementReader $measurements, private Clock $clock, private ?ChangeEvidence $evidence = null) {}
    /** @param string|null $pageUrl the page the work was done on (a team task's page), else the finding's page */
    public function completed(Project $project, StoredOpportunity $opportunity, ?string $annotationId, string $source, ?string $pageUrl = null): void
    {
        $date = $this->clock->now()->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
        $baselineRange = DateRange::endingOn((new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d'), 28);
        $baseline = $this->measurements->measure($project->id, OpportunityConsolidator::key($opportunity), $baselineRange);
        $this->repository->record($project->id, $project->workspaceId, $opportunity, $date, $baseline, $annotationId, $source, $pageUrl ?? OpportunityConsolidator::pageUrl($opportunity));
    }
    /** Twenty outcomes per pass; incomplete imports rotate and retry on a later day. */
    public function measureDue(): int
    {
        $now = $this->clock->now();
        $count = 0;
        foreach ($this->repository->due($now->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('Y-m-d'), $now->format('Y-m-d')) as $row) {
            $done = new \DateTimeImmutable($row->completedOn);
            $baseline = $row->baseline ?? $this->measurements->measure($row->projectId, $row->subject, DateRange::endingOn($done->modify('-1 day')->format('Y-m-d'), 28));
            $actual = $this->measurements->measure($row->projectId, $row->subject, DateRange::endingOn($done->modify('+' . $row->checkpoint . ' days')->format('Y-m-d'), 28));
            $this->repository->measured($row, $baseline, $actual, $now->format('Y-m-d H:i:s'));
            if ($baseline !== null && $actual !== null) {
                $this->repository->setOverlaps($row, $this->overlaps($row));
                $count++;
            }
        }
        return $count;
    }

    /**
     * Marks completed opportunities whose page changed within VERIFY_DAYS of
     * completion as verified (ADR-039, E2). A user's confirmation is never
     * overwritten; outcomes without a page or a detected change stay unverified.
     */
    public function verifyPending(): int
    {
        if ($this->evidence === null) {
            return 0;
        }
        $now = $this->clock->now();
        $verified = 0;
        foreach ($this->repository->unverified($now->modify('-' . self::VERIFY_LOOKBACK_DAYS . ' days')->format('Y-m-d'), self::VERIFY_BATCH) as $row) {
            if ($row->pageUrl === null) {
                continue;
            }
            $done = new \DateTimeImmutable($row->completedOn);
            $changes = $this->evidence->pageChanges($row->projectId, $row->pageUrl, $done->modify('-' . OutcomeOverlaps::VERIFY_DAYS . ' days')->format('Y-m-d') . ' 00:00:00', $done->modify('+' . OutcomeOverlaps::VERIFY_DAYS . ' days')->format('Y-m-d') . ' 23:59:59');
            if ($changes !== []) {
                $this->repository->setImplementation($row, OpportunityOutcome::VERIFIED, 'page_change:' . $changes[0]['public_id'], $now->format('Y-m-d H:i:s'));
                $verified++;
            }
        }

        return $verified;
    }

    /**
     * A user's (or MCP client's) word on whether the change was made.
     * Returns the updated outcome, or null when it is not in this project.
     */
    public function confirm(Project $project, string $outcomeId, bool $implemented, string $source): ?OpportunityOutcome
    {
        $row = $this->repository->find($project->id, $project->workspaceId, $outcomeId);
        if ($row === null) {
            return null;
        }
        $this->repository->setImplementation($row, $implemented ? OpportunityOutcome::CONFIRMED : OpportunityOutcome::NOT_IMPLEMENTED, $source, $this->clock->now()->format('Y-m-d H:i:s'));

        return $this->repository->find($project->id, $project->workspaceId, $outcomeId);
    }

    public function find(Project $project, string $outcomeId): ?OpportunityOutcome
    {
        return $this->repository->find($project->id, $project->workspaceId, $outcomeId);
    }

    /** @return list<array{kind: string, label: string, date: string, ref: ?string}> */
    private function overlaps(OpportunityOutcome $row): array
    {
        $done = new \DateTimeImmutable($row->completedOn);
        $from = $done->modify('-' . OutcomeOverlaps::BEFORE_DAYS . ' days')->format('Y-m-d');
        $to = $done->modify('+' . $row->checkpoint . ' days')->format('Y-m-d');
        $notes = $this->evidence?->notes($row->projectId, $row->workspaceId, $row->pageUrl, $from, $to) ?? [];
        $pageChanges = $this->evidence !== null && $row->pageUrl !== null ? $this->evidence->pageChanges($row->projectId, $row->pageUrl, $from . ' 00:00:00', $to . ' 23:59:59') : [];

        return OutcomeOverlaps::find($row, $notes, $this->repository->completions($row->projectId, $from, $to), $pageChanges);
    }
}
