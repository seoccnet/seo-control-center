<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Support;

use DateTimeImmutable;

/**
 * Deterministic clock for tests and time-dependent calculations.
 */
final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
