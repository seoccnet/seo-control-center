<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

use SeoControlCenter\Domain\Shared\DateRange;

/**
 * How a Google ranking update moved a site (1.6.0): the WINDOW days before
 * the rollout started against the WINDOW days after it ended, so the
 * rollout itself, when rankings swing back and forth, is left out. Both
 * windows have the same length, so totals compare directly.
 */
final readonly class UpdateImpact
{
    public const int WINDOW = 28;
    public const int MOVERS = 15;
    /** Rows with fewer clicks than this in both windows are noise, not movers. */
    private const int MIN_CLICKS = 3;

    /**
     * @param array{name: string, start: string, end: ?string} $update
     */
    public static function before(array $update): DateRange
    {
        $start = new \DateTimeImmutable($update['start']);

        return new DateRange($start->modify('-' . self::WINDOW . ' days')->format('Y-m-d'), $start->modify('-1 day')->format('Y-m-d'));
    }

    /**
     * @param array{name: string, start: string, end: ?string} $update
     * @return DateRange|null null while the update is still rolling out
     */
    public static function after(array $update): ?DateRange
    {
        if ($update['end'] === null) {
            return null;
        }
        $end = new \DateTimeImmutable($update['end']);

        return new DateRange($end->modify('+1 day')->format('Y-m-d'), $end->modify('+' . self::WINDOW . ' days')->format('Y-m-d'));
    }

    /**
     * Rows that gained and lost the most clicks between the windows.
     *
     * @param list<DimensionRow> $before
     * @param list<DimensionRow> $after
     * @return array{winners: list<array{key: string, before: Metrics, after: Metrics, delta: int}>, losers: list<array{key: string, before: Metrics, after: Metrics, delta: int}>}
     */
    public static function movers(array $before, array $after): array
    {
        $rows = [];
        foreach ($before as $row) {
            $rows[$row->key]['before'] = $row->metrics;
        }
        foreach ($after as $row) {
            $rows[$row->key]['after'] = $row->metrics;
        }
        $moves = [];
        foreach ($rows as $key => $pair) {
            $b = $pair['before'] ?? Metrics::zero();
            $a = $pair['after'] ?? Metrics::zero();
            if (max($a->clicks, $b->clicks) < self::MIN_CLICKS || $a->clicks === $b->clicks) {
                continue;
            }
            $moves[] = ['key' => (string) $key, 'before' => $b, 'after' => $a, 'delta' => $a->clicks - $b->clicks];
        }
        $winners = array_values(array_filter($moves, static fn(array $m): bool => $m['delta'] > 0));
        $losers = array_values(array_filter($moves, static fn(array $m): bool => $m['delta'] < 0));
        usort($winners, static fn(array $x, array $y): int => [$y['delta'], $x['key']] <=> [$x['delta'], $y['key']]);
        usort($losers, static fn(array $x, array $y): int => [$x['delta'], $x['key']] <=> [$y['delta'], $y['key']]);

        return ['winners' => array_slice($winners, 0, self::MOVERS), 'losers' => array_slice($losers, 0, self::MOVERS)];
    }

    /** Percentage change, or null without a baseline. */
    public static function percent(int|float $before, int|float $after): ?float
    {
        return $before > 0 ? round(($after - $before) / $before * 100, 1) : null;
    }
}
