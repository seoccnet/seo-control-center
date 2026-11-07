<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\RateLimit;

/**
 * Rate limiter for tests. Windows never expire.
 */
final class InMemoryRateLimiter implements RateLimiter
{
    /** @var array<string, int> */
    private array $hits = [];

    public function hit(string $key, int $maxHits, int $windowSeconds): RateLimitResult
    {
        $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;

        return $this->peek($key, $maxHits);
    }

    public function peek(string $key, int $maxHits): RateLimitResult
    {
        $hits = $this->hits[$key] ?? 0;

        return new RateLimitResult($hits <= $maxHits, $hits, $hits > 0 ? 60 : 0);
    }

    public function clear(string $key): void
    {
        unset($this->hits[$key]);
    }
}
