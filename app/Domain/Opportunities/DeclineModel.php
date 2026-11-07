<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\SearchConsole\Metrics;

final class DeclineModel
{
    /** @return array{detect: bool, evidence: list<array{label: string, value: string}>, seasonal: bool} */
    public static function traffic(Metrics $current, Metrics $previous, ?Metrics $older, ?float $seasonalRatio): array
    {
        // Equal-length click counts: conservative approximate Poisson rate comparison.
        $z = ($previous->clicks - $current->clicks) / sqrt(max(1, $previous->clicks + $current->clicks));
        $ratio = $previous->clicks > 0 ? $current->clicks / $previous->clicks : 1.0;
        $seasonal = $seasonalRatio !== null && $seasonalRatio < 1;
        $matchesSeason = $seasonal && $ratio >= $seasonalRatio * .9;
        $evidence = [['label' => 'Decline significance', 'value' => sprintf('Equal 28-day click-count z = %.2f; require >1.96. Approximate rate comparison, not a causal test.', $z)], ['label' => 'Three-period trend', 'value' => $older !== null ? sprintf('%d → %d → %d clicks; %s', $older->clicks, $previous->clicks, $current->clicks, $older->clicks > $previous->clicks && $previous->clicks > $current->clicks ? 'sustained decline' : 'one-period drop or mixed trend') : 'Older 28-day page sample unknown; sustained trend unconfirmed']];
        $evidence[] = ['label' => 'Seasonality', 'value' => $seasonalRatio !== null ? sprintf('Prior-year completed-month ratio %.3f, observed 28-day ratio %.3f; %s. Monthly context is approximate, not matched daily cohorts.', $seasonalRatio, $ratio, $matchesSeason ? 'consistent with seasonal dip; no decline finding' : ($seasonal ? 'drop exceeds seasonal context' : 'no prior-year seasonal dip')) : 'Unknown: need 13 consecutive complete monthly rollups and both prior-year page months'];
        return ['detect' => $z > 1.96 && !$matchesSeason, 'evidence' => $evidence, 'seasonal' => $seasonal];
    }
}
