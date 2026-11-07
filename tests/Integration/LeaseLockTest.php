<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Database\LeaseLock;

/**
 * TESTING.md -> Locks (ADR-015).
 */
final class LeaseLockTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->statement(LeaseLock::createTableSql());
    }

    public function testOnlyOneHolderAtATime(): void
    {
        $a = new LeaseLock($this->db, 'scheduler', 60);
        $b = new LeaseLock($this->newConnection(), 'scheduler', 60);

        self::assertTrue($a->acquire());
        self::assertFalse($b->acquire());

        $a->release();
        self::assertTrue($b->acquire());
    }

    public function testExpiredLeaseCanBeTakenOver(): void
    {
        $a = new LeaseLock($this->db, 'scheduler', 60);
        self::assertTrue($a->acquire());

        // Simulate a crashed holder whose lease ran out.
        $this->db->execute("UPDATE locks SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE name = 'scheduler'");

        $b = new LeaseLock($this->newConnection(), 'scheduler', 60);
        self::assertTrue($b->acquire());
        self::assertFalse($a->renew(), 'The old holder must notice it lost the lock.');
    }

    public function testNonOwnerCannotRelease(): void
    {
        $a = new LeaseLock($this->db, 'scheduler', 60);
        $b = new LeaseLock($this->newConnection(), 'scheduler', 60);
        self::assertTrue($a->acquire());
        self::assertFalse($b->acquire());

        $b->release(); // b never held it: must be a no-op

        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM locks WHERE name = 'scheduler'"));
        self::assertTrue($a->renew());
    }

    public function testDifferentNamesDoNotConflict(): void
    {
        self::assertTrue((new LeaseLock($this->db, 'worker:1', 60))->acquire());
        self::assertTrue((new LeaseLock($this->db, 'worker:2', 60))->acquire());
    }
}
