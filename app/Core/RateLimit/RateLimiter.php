<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\RateLimit;

interface RateLimiter
{
    /**
     * Records one hit for the key and reports whether it is within the limit.
     */
    public function hit(string $key, int $maxHits, int $windowSeconds): RateLimitResult;

    /**
     * Reports the current state without recording a hit.
     */
    public function peek(string $key, int $maxHits): RateLimitResult;

    public function clear(string $key): void;
}
