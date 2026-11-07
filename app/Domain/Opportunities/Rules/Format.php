<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;

/**
 * Shared formatting and constants for rule evidence.
 */
final class Format
{
    /** Search Console windows are 28 days; opportunities speak in months. */
    public const float MONTH_FACTOR = 30 / 28;

    private function __construct() {}

    public static function int(float|int $value): string
    {
        return number_format((float) $value);
    }

    public static function percent(float $ratio): string
    {
        return number_format($ratio * 100, 1) . ' %';
    }

    /**
     * Effort from keyword difficulty (0-100): up to 30 low, up to 60 medium, above high.
     */
    public static function effortFromDifficulty(?int $difficulty, int $default): int
    {
        return match (true) {
            $difficulty === null => $default,
            $difficulty <= 30 => Opportunity::EFFORT_LOW,
            $difficulty <= 60 => Opportunity::EFFORT_MEDIUM,
            default => Opportunity::EFFORT_HIGH,
        };
    }

    public static function difficulty(?int $difficulty): string
    {
        return $difficulty === null ? 'unknown' : $difficulty . ' / 100';
    }

    public static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $url;
    }

    public static function position(?float $position): string
    {
        if ($position === null) {
            return 'not in the top 100';
        }
        $rounded = round($position, 1);

        return number_format($rounded, $rounded === floor($rounded) ? 0 : 1);
    }
}
