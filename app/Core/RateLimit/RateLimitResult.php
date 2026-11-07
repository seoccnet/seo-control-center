<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\RateLimit;

/**
 * State of a rate-limit bucket. `allowed` means hits <= max; `retryAfterSeconds`
 * is the time until the current window resets (0 when the bucket is empty).
 */
final readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public int $hits,
        public int $retryAfterSeconds,
    ) {}
}
