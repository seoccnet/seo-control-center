<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * Property totals for a day Google has not finalized yet. $complete is false
 * for days Google still collects data for (at least today).
 */
final readonly class FreshDay
{
    public function __construct(
        public string $date,
        public Metrics $metrics,
        public bool $complete,
    ) {}
}
