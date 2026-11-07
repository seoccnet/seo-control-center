<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * One row of a breakdown: a query, page URL, device or country with its metrics.
 */
final readonly class DimensionRow
{
    public function __construct(
        public string $key,
        public Metrics $metrics,
    ) {}
}
