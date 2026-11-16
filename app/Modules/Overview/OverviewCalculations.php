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

    /**
     * Shares of a low-cardinality breakdown (countries, devices): the first
     * $top rows by clicks, the rest summed as "other". Shares use clicks, or
     * impressions when the period has no clicks. A row's previous clicks are
     * set only when the earlier period was imported completely.
     *
     * @param list<DimensionRow> $current all rows of the period
     * @param list<DimensionRow>|null $previous all rows of the previous period, null when not comparable
     * @return array{metric: 'clicks'|'impressions', total: int, rows: list<array{key: string, metrics: Metrics, share: float, previousClicks: ?int}>, other: array{count: int, clicks: int, impressions: int, share: float}}
     */
    public static function breakdown(array $current, ?array $previous, int $top): array
    {
        $clicks = array_sum(array_map(static fn(DimensionRow $r): int => $r->metrics->clicks, $current));
        $metric = $clicks > 0 ? 'clicks' : 'impressions';
        $value = static fn(DimensionRow $r): int => $metric === 'clicks' ? $r->metrics->clicks : $r->metrics->impressions;
        $total = $metric === 'clicks' ? $clicks : array_sum(array_map($value, $current));
        usort($current, static fn(DimensionRow $a, DimensionRow $b): int => [$value($b), $b->metrics->impressions, $a->key] <=> [$value($a), $a->metrics->impressions, $b->key]);
        $before = $previous === null ? null : array_column(array_map(static fn(DimensionRow $r): array => [$r->key, $r->metrics->clicks], $previous), 1, 0);
        $share = static fn(int $v): float => $total > 0 ? $v / $total : 0.0;
        $rows = array_map(static fn(DimensionRow $r): array => ['key' => $r->key, 'metrics' => $r->metrics, 'share' => $share($value($r)), 'previousClicks' => $before === null ? null : (int) ($before[$r->key] ?? 0)], array_slice($current, 0, $top));
        $rest = array_slice($current, $top);
        $otherValue = array_sum(array_map($value, $rest));

        return [
            'metric' => $metric,
            'total' => $total,
            'rows' => $rows,
            'other' => ['count' => count($rest), 'clicks' => array_sum(array_map(static fn(DimensionRow $r): int => $r->metrics->clicks, $rest)), 'impressions' => array_sum(array_map(static fn(DimensionRow $r): int => $r->metrics->impressions, $rest)), 'share' => $share($otherValue)],
        ];
    }

    /**
     * GA4 sessions per AI assistant, most first. Session sources of one
     * assistant (chatgpt.com, chat.openai.com) are summed under its name.
     *
     * @param list<array{source: string, sessions: int, key_events: float, revenue: float}> $rows
     * @return list<array{assistant: string, sessions: int, keyEvents: float}>
     */
    public static function assistants(array $rows): array
    {
        $byName = [];
        foreach ($rows as $row) {
            $name = \SeoControlCenter\Domain\Analytics\AiReferralSources::assistant($row['source']) ?? $row['source'];
            $byName[$name] ??= ['assistant' => $name, 'sessions' => 0, 'keyEvents' => 0.0];
            $byName[$name]['sessions'] += $row['sessions'];
            $byName[$name]['keyEvents'] += $row['key_events'];
        }
        $list = array_values($byName);
        usort($list, static fn(array $a, array $b): int => [$b['sessions'], $a['assistant']] <=> [$a['sessions'], $b['assistant']]);

        return $list;
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
