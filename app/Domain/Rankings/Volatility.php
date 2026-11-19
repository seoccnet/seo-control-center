<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * Ranking volatility (1.7.0): how far the project's tracked keywords moved
 * between consecutive checks, against the project's own usual movement.
 * When most keywords move at once, the cause is usually Google (an update,
 * a test), not one page; one keyword moving alone points at that page.
 */
final class Volatility
{
    public const string CALM = 'calm';
    public const string MOVING = 'moving';
    public const string TURBULENT = 'turbulent';
    public const string UNKNOWN = 'unknown';

    /** Keyword pairs a day needs to say anything. */
    public const int MIN_PAIRS = 5;
    /** Days of history the usual movement is taken from. */
    public const int MIN_DAYS = 10;
    /** Average moves below this are calm whatever the usual movement (half a place). */
    private const float FLOOR = 0.5;

    /**
     * @param list<array{date: string, pairs: int, moved: int, average: float}> $days oldest first
     * @return array{
     *     usual: ?float, latest: ?array{date: string, pairs: int, moved: int, average: float, level: string, ratio: ?float},
     *     days: list<array{date: string, pairs: int, moved: int, average: float, level: string, ratio: ?float}>
     * }
     */
    public static function assess(array $days): array
    {
        $days = array_values(array_filter($days, static fn(array $d): bool => $d['pairs'] >= self::MIN_PAIRS));
        $averages = array_map(static fn(array $d): float => $d['average'], $days);
        $usual = count($averages) >= self::MIN_DAYS ? self::median($averages) : null;
        $rated = array_map(static function (array $day) use ($usual): array {
            $ratio = $usual !== null && $usual > 0.0 ? round($day['average'] / $usual, 2) : null;

            return [...$day, 'level' => self::level($day['average'], $usual), 'ratio' => $ratio];
        }, $days);

        return ['usual' => $usual !== null ? round($usual, 3) : null, 'latest' => $rated === [] ? null : $rated[count($rated) - 1], 'days' => $rated];
    }

    public static function level(float $average, ?float $usual): string
    {
        if ($usual === null) {
            return self::UNKNOWN;
        }
        if ($average < self::FLOOR) {
            return self::CALM;
        }
        // A project whose keywords never move: any real movement stands out.
        $base = max($usual, self::FLOOR / 2);

        return match (true) {
            $average >= 2.5 * $base => self::TURBULENT,
            $average >= 1.5 * $base => self::MOVING,
            default => self::CALM,
        };
    }

    /** @param non-empty-list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
