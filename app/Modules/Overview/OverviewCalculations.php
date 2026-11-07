<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Overview;

use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;

/** Deterministic projections of saved evidence, never provider forecasts. */
final class OverviewCalculations
{
    /** @param list<array{key: string, current: Metrics, previous: Metrics}> $rows
     * @return array{gainers: list<array{key: string, current: Metrics, previous: Metrics, delta: int, percent: ?float}>, losers: list<array{key: string, current: Metrics, previous: Metrics, delta: int, percent: ?float}>}
     */
    public static function movers(array $rows): array
    {
        $gainers = $losers = [];
        foreach ($rows as $row) {
            $delta = $row['current']->clicks - $row['previous']->clicks;
            $item = [...$row, 'delta' => $delta, 'percent' => $row['previous']->clicks > 0 ? $delta / $row['previous']->clicks * 100 : null];
            if ($delta > 0) {
                $gainers[] = $item;
            } elseif ($delta < 0) {
                $losers[] = $item;
            }
        }
        usort($gainers, static fn(array $a, array $b): int => [$b['delta'], $b['current']->impressions, $a['key']] <=> [$a['delta'], $a['current']->impressions, $b['key']]);
        usort($losers, static fn(array $a, array $b): int => [$a['delta'], $b['current']->impressions, $a['key']] <=> [$b['delta'], $a['current']->impressions, $b['key']]);
        return ['gainers' => array_slice($gainers, 0, 3), 'losers' => array_slice($losers, 0, 3)];
    }

    /** Compare each query with other observed queries in its position band.
     * At least five peers and 1,000 peer impressions; target needs 200 impressions.
     * @param list<DimensionRow> $rows
     * @return list<array{key: string, metrics: Metrics, baseline: float, peers: int, peerImpressions: int, extraClicks: float}>
     */
    public static function ctrActions(array $rows): array
    {
        $actions = [];
        foreach ($rows as $row) {
            $m = $row->metrics;
            if ($m->impressions < 200 || $m->position < 1 || $m->position > 20) {
                continue;
            }
            $peers = array_values(array_filter($rows, static fn(DimensionRow $other): bool => $other->key !== $row->key && $other->metrics->impressions >= 50 && self::band($other->metrics->position) === self::band($m->position)));
            $impressions = array_sum(array_map(static fn(DimensionRow $r): int => $r->metrics->impressions, $peers));
            if (count($peers) < 5 || $impressions < 1000) {
                continue;
            }
            $baseline = array_sum(array_map(static fn(DimensionRow $r): int => $r->metrics->clicks, $peers)) / $impressions;
            if ($baseline <= $m->ctr() || $m->ctr() >= $baseline * 0.5) {
                continue;
            }
            $actions[] = ['key' => $row->key, 'metrics' => $m, 'baseline' => $baseline, 'peers' => count($peers), 'peerImpressions' => $impressions, 'extraClicks' => round($m->impressions * ($baseline - $m->ctr()), 1)];
        }
        usort($actions, static fn(array $a, array $b): int => [$b['extraClicks'], $a['key']] <=> [$a['extraClicks'], $b['key']]);
        return array_slice($actions, 0, 5);
    }

    private static function band(float $position): int
    {
        return match (true) {
            $position < 1 => 0,
            $position <= 3 => 1,
            $position <= 10 => 2,
            $position <= 20 => 3,
            default => 4,
        };
    }
}
