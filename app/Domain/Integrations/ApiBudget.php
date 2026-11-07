<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

/**
 * Spending limits for one provider in one workspace, in USD. A null limit
 * means "no limit". Days and months are UTC calendar periods.
 */
final readonly class ApiBudget
{
    public function __construct(
        public ?float $dailyLimit = null,
        public ?float $monthlyLimit = null,
        public ?float $operationLimit = null,
    ) {}

    public static function unlimited(): self
    {
        return new self();
    }
}
