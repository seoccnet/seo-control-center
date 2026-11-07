<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * A detected, explainable SEO opportunity (INSTRUCTIONS.md §13). The
 * evidence is the application data that triggered the rule; the scores are
 * derived from it, never from an opaque model.
 *
 * - impact: 0-100, from the estimated monthly clicks at stake (log scale)
 * - confidence: 0-100, how reliable the signal is; pure detection rules use 0
 *   until the analyzer applies the shared evidence-based ConfidenceModel
 * - effort: 1 (low) to 3 (high)
 */
final readonly class Opportunity
{
    public const int EFFORT_LOW = 1;
    public const int EFFORT_MEDIUM = 2;
    public const int EFFORT_HIGH = 3;

    /**
     * @param string $subject stable identity within the type, e.g. "query:red shoes"
     * @param list<array{label: string, value: string}> $evidence
     */
    public function __construct(
        public OpportunityType $type,
        public string $subject,
        public string $title,
        public string $explanation,
        public array $evidence,
        public int $impact,
        public int $confidence,
        public int $effort,
        public ?float $estimatedClicks = null,
        public ?int $urlsTouched = null,
        public ?float $rawEstimatedClicks = null,
    ) {}

    public function priority(): float
    {
        return self::priorityOf($this->impact, $this->confidence, $this->effort);
    }

    public static function priorityOf(int $impact, int $confidence, int $effort): float
    {
        return round($impact * $confidence / 100 / max(1, $effort), 2);
    }

    /**
     * Impact from the estimated extra (or lost) clicks per month:
     * 10 clicks ≈ 26, 100 ≈ 50, 1,000 ≈ 75, 10,000+ = 100.
     */
    public static function impactFromClicks(float $clicks): int
    {
        return (int) max(0, min(100, round(25 * log10(1 + max(0.0, $clicks)))));
    }
}
