<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * Search visibility: the share of the clicks the project could get if it
 * ranked first for every tracked keyword, estimated with an organic CTR
 * curve and weighted by search volume. 100 % = position 1 everywhere.
 * Keywords without a known volume count with weight 1.
 */
final class Visibility
{
    /** Typical organic click-through rate by position (positions 1-10). */
    private const array CTR = [1 => 0.28, 2 => 0.15, 3 => 0.11, 4 => 0.08, 5 => 0.07, 6 => 0.05, 7 => 0.04, 8 => 0.03, 9 => 0.03, 10 => 0.025];
    private const float CTR_PAGE_TWO = 0.01;

    private function __construct() {}

    public static function ctr(?int $position): float
    {
        if ($position === null || $position < 1) {
            return 0.0;
        }

        return self::CTR[$position] ?? ($position <= 20 ? self::CTR_PAGE_TWO : 0.0);
    }

    /**
     * @param list<array{position: ?int, volume: ?int}> $keywords
     * @return float percentage 0-100
     */
    public static function percent(array $keywords): float
    {
        $possible = 0.0;
        $actual = 0.0;
        foreach ($keywords as $keyword) {
            $weight = max(1, $keyword['volume'] ?? 1);
            $possible += $weight * self::CTR[1];
            $actual += $weight * self::ctr($keyword['position']);
        }

        return $possible > 0 ? round($actual / $possible * 100, 4) : 0.0;
    }
}
