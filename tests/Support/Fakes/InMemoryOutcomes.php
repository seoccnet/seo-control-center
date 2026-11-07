<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Opportunities\OpportunityConsolidator;
use SeoControlCenter\Domain\Opportunities\OpportunityOutcome;
use SeoControlCenter\Domain\Opportunities\OutcomeCalibration;
use SeoControlCenter\Domain\Opportunities\OutcomeMetrics;
use SeoControlCenter\Domain\Opportunities\OutcomeRepository;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;

final class InMemoryOutcomes implements OutcomeRepository
{
    /** @var array<string, OpportunityOutcome> */
    public array $rows = [];
    /** @var array<string, string> */
    public array $checked = [];
    /** @var array<int, array<string, array{factor: float, samples: int}>> */
    public array $calibrations = [];
    public function __construct(private readonly Clock $clock) {}
    public function record(int $projectId, int $workspaceId, StoredOpportunity $opportunity, string $completedOn, ?OutcomeMetrics $baseline, ?string $annotationId, string $source, ?string $pageUrl = null): void
    {
        foreach ([28, 56] as $checkpoint) {
            $key = $projectId . ':' . $opportunity->publicId . ':' . $completedOn . ':' . $checkpoint;
            $this->rows[$key] ??= new OpportunityOutcome(Uuid::v7($this->clock), $projectId, $workspaceId, $opportunity->publicId, $opportunity->type->value, OpportunityConsolidator::key($opportunity), $completedOn, $checkpoint, $opportunity->estimatedClicks, $baseline, null, $annotationId, null, $opportunity->rawEstimatedClicks ?? $opportunity->estimatedClicks, $pageUrl);
        }
    }

    public function find(int $projectId, int $workspaceId, string $outcomeId): ?OpportunityOutcome
    {
        foreach ($this->rows as $row) {
            if ($row->publicId === $outcomeId && $row->projectId === $projectId && $row->workspaceId === $workspaceId) {
                return $row;
            }
        }

        return null;
    }

    public function unverified(string $since, int $limit): array
    {
        $rows = array_values(array_filter($this->rows, static fn(OpportunityOutcome $r): bool => $r->checkpoint === 28 && $r->implementation === OpportunityOutcome::UNVERIFIED && $r->pageUrl !== null && $r->completedOn >= $since));

        return array_slice($rows, 0, $limit);
    }

    public function completions(int $projectId, string $from, string $to): array
    {
        return array_values(array_filter($this->rows, static fn(OpportunityOutcome $r): bool => $r->projectId === $projectId && $r->checkpoint === 28 && $r->completedOn >= $from && $r->completedOn <= $to));
    }

    public function setImplementation(OpportunityOutcome $row, string $state, ?string $source, string $at): void
    {
        foreach ($this->rows as $key => $r) {
            if ($r->projectId === $row->projectId && $r->opportunityId === $row->opportunityId && $r->completedOn === $row->completedOn) {
                $this->rows[$key] = self::with($r, implementation: $state, source: $source, at: $at);
            }
        }
    }

    public function setOverlaps(OpportunityOutcome $row, array $overlaps): void
    {
        foreach ($this->rows as $key => $r) {
            if ($r->publicId === $row->publicId) {
                $this->rows[$key] = self::with($r, overlaps: $overlaps);
            }
        }
    }

    /** @param list<array{kind: string, label: string, date: string, ref: ?string}>|null $overlaps */
    private static function with(OpportunityOutcome $r, ?string $implementation = null, ?string $source = null, ?string $at = null, ?array $overlaps = null, ?OutcomeMetrics $baseline = null, ?OutcomeMetrics $actual = null, ?string $measuredAt = null, bool $measure = false): OpportunityOutcome
    {
        return new OpportunityOutcome($r->publicId, $r->projectId, $r->workspaceId, $r->opportunityId, $r->type, $r->subject, $r->completedOn, $r->checkpoint, $r->predictedClicks, $measure ? $baseline : $r->baseline, $measure ? $actual : $r->actual, $r->annotationId, $measure ? $measuredAt : $r->measuredAt, $r->rawPredictedClicks, $r->pageUrl, $implementation ?? $r->implementation, $implementation !== null ? $source : $r->implementationSource, $implementation !== null ? $at : $r->implementationAt, $overlaps ?? $r->overlaps);
    }
    public function due(string $today, string $retryDate): array
    {
        $rows = array_filter($this->rows, fn(OpportunityOutcome $row): bool => (str_starts_with($row->subject, 'query:') || str_starts_with($row->subject, 'page:')) && $row->measuredAt === null && (new \DateTimeImmutable($row->completedOn))->modify('+' . ($row->checkpoint + 2) . ' days')->format('Y-m-d') <= $today && ($this->checked[$row->publicId] ?? '') < $retryDate . ' 00:00:00');
        usort($rows, fn(OpportunityOutcome $a, OpportunityOutcome $b): int => [$this->checked[$a->publicId] ?? '', $a->completedOn, $a->publicId] <=> [$this->checked[$b->publicId] ?? '', $b->completedOn, $b->publicId]);
        return array_slice($rows, 0, 20);
    }
    public function measured(OpportunityOutcome $row, ?OutcomeMetrics $baseline, ?OutcomeMetrics $actual, string $checkedAt): void
    {
        foreach ($this->rows as $key => $known) {
            if ($known->publicId === $row->publicId && $known->projectId === $row->projectId && $known->workspaceId === $row->workspaceId && $known->measuredAt === null) {
                $this->rows[$key] = self::with($known, baseline: $known->baseline ?? $baseline, actual: $actual, measuredAt: $baseline !== null && $actual !== null ? $checkedAt : null, measure: true);
                $this->checked[$row->publicId] = $checkedAt;
            }
        }
    }
    public function factors(int $projectId, string $since): array
    {
        if (isset($this->calibrations[$projectId])) {
            return $this->calibrations[$projectId];
        }
        $ratios = [];
        foreach ($this->rows as $row) {
            if ($row->projectId === $projectId && $row->checkpoint === 56 && $row->completedOn >= $since && $row->measuredAt !== null && $row->predictedClicks !== null && $row->predictedClicks > 0 && $row->baseline !== null && $row->actual !== null && $row->baseline->impressions >= 200 && $row->actual->impressions >= 200 && $row->implementation !== OpportunityOutcome::NOT_IMPLEMENTED) {
                $ratios[$row->type][$row->clean() ? 'clean' : 'other'][] = max(0.0, min(2.0, (($row->actual->clicks - $row->baseline->clicks) * 30 / 28) / ($row->rawPredictedClicks ?? $row->predictedClicks)));
            }
        }

        return OutcomeCalibration::preferClean($ratios);
    }
    public function recent(int $projectId): array
    {
        $rows = array_values(array_filter($this->rows, static fn(OpportunityOutcome $row): bool => $row->projectId === $projectId));
        usort($rows, static fn(OpportunityOutcome $a, OpportunityOutcome $b): int => [$b->completedOn, $b->publicId] <=> [$a->completedOn, $a->publicId]);
        return array_slice($rows, 0, 50);
    }
}
