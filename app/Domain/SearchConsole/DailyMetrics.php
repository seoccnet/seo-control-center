<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

final readonly class DailyMetrics
{
    /**
     * @param string $date Search Console date (YYYY-MM-DD, Pacific Time)
     */
    public function __construct(
        public string $date,
        public Metrics $metrics,
    ) {}
}
