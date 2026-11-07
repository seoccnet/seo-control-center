<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Analytics;

/**
 * GA4 sessions on the project's hosts referred by AI assistants (0.43.0),
 * per day and session source. Days without such sessions have no row.
 */
final readonly class Ga4AiReferralReport
{
    /** @param list<array{date: string, source: string, sessions: int, key_events: float, revenue: float}> $rows */
    public function __construct(public array $rows, public string $currency, public bool $limited) {}
}
