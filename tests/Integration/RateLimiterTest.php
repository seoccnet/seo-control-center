<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\RateLimit\PdoRateLimiter;

final class RateLimiterTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, [dirname(__DIR__, 2) . '/database/migrations'], new \Psr\Log\NullLogger()))->migrate();
    }

    public function testCountsWithinWindowAndResets(): void
    {
        $limiter = new PdoRateLimiter($this->db);

        self::assertTrue($limiter->hit('k', 2, 60)->allowed);
        self::assertTrue($limiter->hit('k', 2, 60)->allowed);
        $third = $limiter->hit('k', 2, 60);
        self::assertFalse($third->allowed);
        self::assertSame(3, $third->hits);
        self::assertGreaterThan(0, $third->retryAfterSeconds);

        // Window expired: counting starts again.
        $this->db->execute('UPDATE rate_limit_buckets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND');
        self::assertSame(0, $limiter->peek('k', 2)->hits);
        self::assertSame(1, $limiter->hit('k', 2, 60)->hits);

        $limiter->clear('k');
        self::assertSame(0, $limiter->peek('k', 2)->hits);
    }

    public function testKeysAreIndependent(): void
    {
        $limiter = new PdoRateLimiter($this->db);
        $limiter->hit('a', 1, 60);
        $limiter->hit('a', 1, 60);

        self::assertFalse($limiter->peek('a', 1)->allowed);
        self::assertTrue($limiter->peek('b', 1)->allowed);
    }
}
