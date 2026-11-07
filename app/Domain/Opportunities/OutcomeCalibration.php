<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

final class OutcomeCalibration
{
    /** Ten neutral prior observations protect small cohorts; five measured completions minimum. */
    public static function factor(float $meanRatio, int $samples): float
    {
        return $samples < 5 ? 1.0 : max(.25, min(1.5, (10 + max(0.0, min(2.0, $meanRatio)) * $samples) / (10 + $samples)));
    }

    /**
     * Factors per type, from verified outcomes without overlapping changes
     * when a type has at least five of them, otherwise from all outcomes
     * (ADR-039, E2). Ratios are already clipped to 0–2.
     *
     * @param array<string, array{clean?: list<float>, other?: list<float>}> $ratios
     * @return array<string, array{factor: float, samples: int}>
     */
    public static function preferClean(array $ratios): array
    {
        $result = [];
        foreach ($ratios as $type => $groups) {
            $clean = $groups['clean'] ?? [];
            $values = count($clean) >= 5 ? $clean : [...$clean, ...($groups['other'] ?? [])];
            if ($values !== []) {
                $result[(string) $type] = ['factor' => self::factor(array_sum($values) / count($values), count($values)), 'samples' => count($values)];
            }
        }

        return $result;
    }
}
