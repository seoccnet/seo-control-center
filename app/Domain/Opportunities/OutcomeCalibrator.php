<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

final readonly class OutcomeCalibrator
{
    /** @param array<string, array{factor: float, samples: int}> $factors */
    public function __construct(private array $factors) {}
    public function adjust(Opportunity $row): Opportunity
    {
        $observed = $this->factors[$row->type->value] ?? ['factor' => 1.0, 'samples' => 0];
        $factor = $observed['samples'] >= 5 ? max(.25, min(1.5, $observed['factor'])) : 1.0;
        $raw = $row->rawEstimatedClicks ?? $row->estimatedClicks;
        $estimate = $raw !== null ? max(0.0, $raw) * $factor : null;
        $evidence = [...$row->evidence, ['label' => 'Outcome calibration', 'value' => sprintf('Project/type realization factor %.3f from %d eligible 56-day completions in the last year; at least five required, ten neutral prior observations, individual ratios clipped 0–2, final factor bounded 0.25–1.5. %s Correlation, not attribution.', $factor, $observed['samples'], $estimate !== null ? sprintf('Raw %.1f monthly clicks × factor = %.1f adjusted clicks.', $raw, $estimate) : 'Unknown gains remain unknown; technical exposure is not calibrated as incremental gain.')]];
        return new Opportunity($row->type, $row->subject, $row->title, $row->explanation, $evidence, $estimate !== null ? Opportunity::impactFromClicks($estimate) : $row->impact, $row->confidence, $row->effort, $estimate, $row->urlsTouched, $row->rawEstimatedClicks ?? $row->estimatedClicks);
    }
}
