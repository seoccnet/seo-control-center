<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

final readonly class OpportunityOutcome
{
    /** Not checked against what changed yet. */
    public const string UNVERIFIED = 'unverified';
    /** A watched page change was detected around the completion date (ADR-039, E2). */
    public const string VERIFIED = 'verified';
    /** A team member or AI client confirmed the work was done. */
    public const string CONFIRMED = 'confirmed';
    /** A team member said the work was not done after all. */
    public const string NOT_IMPLEMENTED = 'not_implemented';
    public const array IMPLEMENTATION = [self::UNVERIFIED, self::VERIFIED, self::CONFIRMED, self::NOT_IMPLEMENTED];

    /**
     * @param list<array{kind: string, label: string, date: string, ref: ?string}> $overlaps other changes in the measurement window
     */
    public function __construct(public string $publicId, public int $projectId, public int $workspaceId, public string $opportunityId, public string $type, public string $subject, public string $completedOn, public int $checkpoint, public ?float $predictedClicks, public ?OutcomeMetrics $baseline, public ?OutcomeMetrics $actual, public ?string $annotationId, public ?string $measuredAt, public ?float $rawPredictedClicks = null, public ?string $pageUrl = null, public string $implementation = self::UNVERIFIED, public ?string $implementationSource = null, public ?string $implementationAt = null, public array $overlaps = []) {}

    public function monthlyChange(): ?float
    {
        return $this->baseline !== null && $this->actual !== null ? ($this->actual->clicks - $this->baseline->clicks) * 30 / 28 : null;
    }

    /** Verified or confirmed, and no other change overlapped its window: the cleanest evidence. */
    public function clean(): bool
    {
        return in_array($this->implementation, [self::VERIFIED, self::CONFIRMED], true) && $this->overlaps === [];
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'outcome_id' => $this->publicId,
            'opportunity_id' => $this->opportunityId,
            'type' => $this->type,
            'subject' => $this->subject,
            'page_url' => $this->pageUrl,
            'completed_on' => $this->completedOn,
            'checkpoint_days' => $this->checkpoint,
            'predicted_monthly_clicks' => $this->predictedClicks,
            'baseline' => $this->baseline === null ? null : ['clicks' => $this->baseline->clicks, 'impressions' => $this->baseline->impressions, 'position' => $this->baseline->position],
            'observed' => $this->actual === null ? null : ['clicks' => $this->actual->clicks, 'impressions' => $this->actual->impressions, 'position' => $this->actual->position],
            'observed_monthly_change' => $this->monthlyChange(),
            'measured_at' => $this->measuredAt,
            'implementation' => $this->implementation,
            'implementation_source' => $this->implementationSource,
            'implementation_at' => $this->implementationAt,
            'overlaps' => $this->overlaps,
        ];
    }
}
