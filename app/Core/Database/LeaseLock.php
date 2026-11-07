<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Database;

/**
 * Named mutex stored in the `locks` table as an expiring lease (ADR-015).
 *
 * Safe across connections and processes; a crashed holder's lease expires
 * automatically. Times are computed in SQL (UTC_TIMESTAMP) so PHP/DB clock
 * drift cannot cause two holders.
 */
final class LeaseLock
{
    private ?string $token = null;

    public function __construct(
        private readonly Connection $db,
        private readonly string $name,
        private readonly int $ttlSeconds,
    ) {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Lock TTL must be positive.');
        }
        if ($name === '' || strlen($name) > 191) {
            throw new \InvalidArgumentException('Lock name must be 1-191 characters.');
        }
    }

    public function acquire(): bool
    {
        $token = bin2hex(random_bytes(16));

        // Column order matters: expires_at must be assigned last so the
        // IF() conditions above it still see the old value (QUEUE_AND_CRON.md -> Locks).
        $this->db->execute(
            'INSERT INTO locks (name, owner, acquired_at, expires_at)
             VALUES (:name, :owner, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL :ttl SECOND)
             ON DUPLICATE KEY UPDATE
               owner       = IF(expires_at < UTC_TIMESTAMP(), VALUES(owner), owner),
               acquired_at = IF(expires_at < UTC_TIMESTAMP(), VALUES(acquired_at), acquired_at),
               expires_at  = IF(expires_at < UTC_TIMESTAMP(), VALUES(expires_at), expires_at)',
            ['name' => $this->name, 'owner' => $token, 'ttl' => $this->ttlSeconds],
        );

        $owner = $this->db->fetchValue('SELECT owner FROM locks WHERE name = :name', ['name' => $this->name]);
        if ($owner === $token) {
            $this->token = $token;

            return true;
        }

        return false;
    }

    /**
     * Extends the lease. Returns false if the lock was lost (expired and taken over).
     */
    public function renew(): bool
    {
        if ($this->token === null) {
            return false;
        }
        $this->db->execute(
            'UPDATE locks SET expires_at = UTC_TIMESTAMP() + INTERVAL :ttl SECOND WHERE name = :name AND owner = :owner',
            ['ttl' => $this->ttlSeconds, 'name' => $this->name, 'owner' => $this->token],
        );

        // rowCount() can be 0 when the value did not change within the same second,
        // so verify ownership explicitly.
        return $this->db->fetchValue(
            'SELECT owner FROM locks WHERE name = :name',
            ['name' => $this->name],
        ) === $this->token;
    }

    public function release(): void
    {
        if ($this->token === null) {
            return;
        }
        $this->db->execute('DELETE FROM locks WHERE name = :name AND owner = :owner', ['name' => $this->name, 'owner' => $this->token]);
        $this->token = null;
    }

    public function isHeld(): bool
    {
        return $this->token !== null;
    }

    /**
     * Returns the SQL that creates the locks table. The migrator creates it
     * itself because migrations need the lock before any migration runs.
     */
    public static function createTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS locks (
            name        VARCHAR(191) NOT NULL,
            owner       CHAR(32)     NOT NULL,
            acquired_at DATETIME     NOT NULL,
            expires_at  DATETIME     NOT NULL,
            PRIMARY KEY (name),
            KEY locks_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
}
