<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\InternalLinks;

/**
 * Internal link strength (1.8.0): PageRank over a site's own link graph.
 * Each page passes its strength on, split over the pages it links to;
 * pages without links pass theirs to every page alike. The result is
 * turned into a percentile so it reads the same on small and large sites.
 */
final class LinkRank
{
    public const float DAMPING = 0.85;
    private const int ITERATIONS = 50;
    private const float TOLERANCE = 1.0e-9;

    private function __construct() {}

    /**
     * @param list<int> $nodes
     * @param list<array{0: int, 1: int}> $edges from => to; edges to unknown nodes and self links are ignored
     * @return array<int, float> node => rank (sums to 1)
     */
    public static function ranks(array $nodes, array $edges): array
    {
        $count = count($nodes);
        if ($count === 0) {
            return [];
        }
        $known = array_fill_keys($nodes, true);
        $out = [];
        $in = [];
        foreach ($edges as [$from, $to]) {
            if ($from === $to || !isset($known[$from], $known[$to]) || isset($out[$from][$to])) {
                continue;
            }
            $out[$from][$to] = true;
            $in[$to][] = $from;
        }
        $degree = array_map(count(...), $out);
        $rank = array_fill_keys($nodes, 1 / $count);
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $dangling = 0.0;
            foreach ($nodes as $node) {
                if (!isset($degree[$node])) {
                    $dangling += $rank[$node];
                }
            }
            $base = (1 - self::DAMPING) / $count + self::DAMPING * $dangling / $count;
            $next = [];
            $delta = 0.0;
            foreach ($nodes as $node) {
                $sum = 0.0;
                foreach ($in[$node] ?? [] as $from) {
                    $sum += $rank[$from] / $degree[$from];
                }
                $next[$node] = $base + self::DAMPING * $sum;
                $delta += abs($next[$node] - $rank[$node]);
            }
            $rank = $next;
            if ($delta < self::TOLERANCE) {
                break;
            }
        }

        return $rank;
    }

    /**
     * Percentile of each rank: the share of pages (0–100) with a lower rank.
     * Equal ranks share the same percentile.
     *
     * @param array<int, float> $ranks
     * @return array<int, int>
     */
    public static function percentiles(array $ranks): array
    {
        $count = count($ranks);
        if ($count < 2) {
            return array_map(static fn(): int => 100, $ranks);
        }
        asort($ranks);
        $result = [];
        $below = 0;
        $previous = null;
        $position = 0;
        foreach ($ranks as $node => $rank) {
            if ($previous === null || abs($rank - $previous) > 1.0e-12) {
                $below = $position;
                $previous = $rank;
            }
            $result[$node] = (int) round($below / ($count - 1) * 100);
            ++$position;
        }

        return $result;
    }
}
