<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * An opportunity as stored for a project, with its workflow status:
 * open (currently detected), done / dismissed (set by a user; they stay so
 * even if the rule keeps detecting it) or resolved (no longer detected).
 */
final readonly class StoredOpportunity
{
    public const string OPEN = 'open';
    public const string DONE = 'done';
    public const string DISMISSED = 'dismissed';
    public const string RESOLVED = 'resolved';
    public const string SNOOZED = 'snoozed';
    public const array STATUSES = [self::OPEN, self::DONE, self::DISMISSED, self::RESOLVED, self::SNOOZED];

    /**
     * @param list<array{label: string, value: string}> $evidence
     */
    public function __construct(
        public string $publicId,
        public OpportunityType $type,
        public string $title,
        public string $explanation,
        public array $evidence,
        public int $impact,
        public int $confidence,
        public int $effort,
        public float $priority,
        public string $status,
        public string $firstDetectedAt,
        public string $lastDetectedAt,
        public ?float $estimatedClicks = null,
        public ?float $rawEstimatedClicks = null,
        public ?string $snoozedUntil = null,
        /** The rule's stable subject, e.g. "crux:https://…" (Opportunity::$subject). */
        public string $subject = '',
    ) {}

    /** @return array<string, mixed> */
    public function present(): array
    {
        return ['opportunity_id' => $this->publicId, 'type' => $this->type->value, 'title' => $this->title, 'explanation' => $this->explanation, 'evidence' => $this->evidence, 'impact' => $this->impact, 'confidence' => $this->confidence, 'effort' => $this->effort, 'priority' => $this->priority, 'status' => $this->status, 'snoozed_until' => $this->snoozedUntil, 'estimated_monthly_clicks' => $this->estimatedClicks, 'raw_estimated_monthly_clicks' => $this->rawEstimatedClicks, 'first_detected_at' => $this->firstDetectedAt, 'last_detected_at' => $this->lastDetectedAt, 'bid_verdict' => $this->verdict(), 'zero_click_risk' => $this->zeroClickRisk(), 'score_breakdown' => ScoreBreakdown::of($this)];
    }

    /** The first evidence value with this label, or null. */
    public function evidenceValue(string $label): ?string
    {
        foreach ($this->evidence as $item) {
            if ($item['label'] === $label) {
                return $item['value'];
            }
        }

        return null;
    }

    /** The finding's query or keyword, or null for page and site findings. */
    public function keyword(): ?string
    {
        return $this->evidenceValue('Query') ?? $this->evidenceValue('Keyword');
    }

    /** BID verdict: target, consider or reconsider; null when not scored. */
    public function verdict(): ?string
    {
        $value = $this->evidenceValue('BID verdict');

        return $value !== null && preg_match('/^(Target|Consider|Reconsider)\b/', $value, $m) === 1 ? strtolower($m[1]) : null;
    }

    /** Business potential 0-3 from the BID check, or null when not scored. */
    public function businessPotential(): ?int
    {
        $value = $this->evidenceValue('Business potential');

        return $value !== null && preg_match('/^([0-3])\/3/', $value, $m) === 1 ? (int) $m[1] : null;
    }

    /** Zero-click risk estimate: high, medium or low; null without a query. */
    public function zeroClickRisk(): ?string
    {
        $value = $this->evidenceValue('Zero-click risk');

        return $value !== null && preg_match('/^(High|Medium|Low)\b/', $value, $m) === 1 ? strtolower($m[1]) : null;
    }
}
