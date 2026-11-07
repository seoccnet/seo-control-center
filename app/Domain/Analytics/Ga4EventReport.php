<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Analytics;

/**
 * Daily GA4 counts of the project's business events (ADR-042): every date of
 * the range and every requested name has a row, zero when GA4 recorded none.
 */
final readonly class Ga4EventReport
{
    /** @param list<array{date: string, event_name: string, organic: int, total: int}> $rows */
    public function __construct(public array $rows, public string $timezone, public bool $limited, public bool $thresholded) {}
}
