<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\RateLimit;

use SeoControlCenter\Core\Database\Connection;

/**
 * Fixed-window rate limiter in MariaDB/MySQL (SECURITY.md -> Rate limiting).
 * Keys are hashed, so raw e-mail addresses or IPs are not stored.
 */
final readonly class PdoRateLimiter implements RateLimiter
{
    public function __construct(private Connection $db) {}

    public function hit(string $key, int $maxHits, int $windowSeconds): RateLimitResult
    {
        $hash = hash('sha256', $key);

        // expires_at must be assigned last: the IF() conditions above it read the old value.
        $this->db->execute(
            'INSERT INTO rate_limit_buckets (bucket_key, hits, window_start, expires_at)
             VALUES (:k, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL :w SECOND)
             ON DUPLICATE KEY UPDATE
               hits         = IF(expires_at <= UTC_TIMESTAMP(), 1, hits + 1),
               window_start = IF(expires_at <= UTC_TIMESTAMP(), VALUES(window_start), window_start),
               expires_at   = IF(expires_at <= UTC_TIMESTAMP(), VALUES(expires_at), expires_at)',
            ['k' => $hash, 'w' => $windowSeconds],
        );

        return $this->result($hash, $maxHits);
    }

    public function peek(string $key, int $maxHits): RateLimitResult
    {
        return $this->result(hash('sha256', $key), $maxHits);
    }

    public function clear(string $key): void
    {
        $this->db->execute('DELETE FROM rate_limit_buckets WHERE bucket_key = ?', [hash('sha256', $key)]);
    }

    private function result(string $hash, int $maxHits): RateLimitResult
    {
        $row = $this->db->fetchOne(
            'SELECT hits, GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at)) AS retry_after
               FROM rate_limit_buckets WHERE bucket_key = ? AND expires_at > UTC_TIMESTAMP()',
            [$hash],
        );
        if ($row === null) {
            return new RateLimitResult(true, 0, 0);
        }
        $hits = (int) $row['hits'];

        return new RateLimitResult($hits <= $maxHits, $hits, (int) $row['retry_after']);
    }
}
