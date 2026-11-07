<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * Combines query or page rows from several search engines (Google and
 * Bing): rows with the same key are added up, positions weighted by
 * impressions, and the result is ordered by clicks, then impressions.
 */
final class DimensionRows
{
    private function __construct() {}

    /**
     * @param list<DimensionRow> ...$sources
     * @return list<DimensionRow>
     */
    public static function merge(array ...$sources): array
    {
        /** @var array<string, list<Metrics>> $byKey */
        $byKey = [];
        foreach ($sources as $rows) {
            foreach ($rows as $row) {
                $byKey[$row->key][] = $row->metrics;
            }
        }
        $merged = [];
        foreach ($byKey as $key => $metrics) {
            $merged[] = new DimensionRow((string) $key, Metrics::sum($metrics));
        }
        usort($merged, static fn(DimensionRow $a, DimensionRow $b): int => [$b->metrics->clicks, $b->metrics->impressions, $a->key] <=> [$a->metrics->clicks, $a->metrics->impressions, $b->key]);

        return $merged;
    }
}
