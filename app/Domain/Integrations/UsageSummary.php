<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

/**
 * Provider spending in USD for the current UTC day and month. Open
 * reservations count as spent until they are settled or released.
 */
final readonly class UsageSummary
{
    public function __construct(
        public float $today,
        public float $month,
        public int $requestsToday,
        public int $requestsMonth,
    ) {}

    public static function none(): self
    {
        return new self(0.0, 0.0, 0, 0);
    }
}
