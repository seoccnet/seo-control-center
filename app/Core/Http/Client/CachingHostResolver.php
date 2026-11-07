<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Client;

/**
 * Remembers successful lookups for a short time within one PHP process.
 *
 * A cron run can send hundreds of requests to one provider host (one SERP
 * request per keyword), and each lookup costs an A and an AAAA query. The
 * cached answer is still checked for public addresses on every request and
 * the connection is pinned to it, so caching cannot widen what is reachable.
 * Empty answers are not cached.
 */
final class CachingHostResolver implements HostResolver
{
    private const int MAX_ENTRIES = 256;

    /** @var array<string, array{0: int, 1: list<string>}> host => [expires, addresses] */
    private array $cache = [];

    public function __construct(
        private readonly HostResolver $inner,
        private readonly int $ttlSeconds = 60,
    ) {}

    public function addresses(string $host): array
    {
        $key = strtolower($host);
        $now = time();
        $hit = $this->cache[$key] ?? null;
        if ($hit !== null && $hit[0] > $now) {
            return $hit[1];
        }

        $addresses = $this->inner->addresses($host);
        if ($addresses !== []) {
            if (count($this->cache) >= self::MAX_ENTRIES) {
                $this->cache = array_filter($this->cache, static fn(array $entry): bool => $entry[0] > $now);
                if (count($this->cache) >= self::MAX_ENTRIES) {
                    array_shift($this->cache);
                }
            }
            $this->cache[$key] = [$now + $this->ttlSeconds, $addresses];
        } else {
            unset($this->cache[$key]);
        }

        return $addresses;
    }
}
