<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Database\DatabaseException;

final class ConnectionTest extends DatabaseTestCase
{
    public function testSessionIsUtcUtf8mb4AndStrict(): void
    {
        self::assertSame('+00:00', $this->db->fetchValue('SELECT @@session.time_zone'));
        self::assertSame('utf8mb4', $this->db->fetchValue('SELECT @@character_set_connection'));
        self::assertStringContainsString('STRICT_TRANS_TABLES', (string) $this->db->fetchValue('SELECT @@session.sql_mode'));
    }

    public function testServerVersionIsSupported(): void
    {
        $version = $this->db->serverVersion();
        self::assertTrue($version->isSupported(), $version->describe() . ' is below the ADR-011 minimum.');
    }

    public function testPreparedParametersAreTyped(): void
    {
        $this->db->statement('CREATE TABLE t (id INT PRIMARY KEY, name VARCHAR(50), flag TINYINT(1)) ENGINE=InnoDB');
        $this->db->execute('INSERT INTO t (id, name, flag) VALUES (:id, :name, :flag)', ['id' => 1, 'name' => "O'Reilly; DROP TABLE t", 'flag' => true]);

        $row = $this->db->fetchOne('SELECT id, name, flag FROM t WHERE id = ?', [1]);
        self::assertSame(['id' => 1, 'name' => "O'Reilly; DROP TABLE t", 'flag' => 1], $row);
    }

    public function testTransactionRollsBackOnException(): void
    {
        $this->db->statement('CREATE TABLE t (id INT PRIMARY KEY) ENGINE=InnoDB');

        try {
            $this->db->transaction(function ($db): void {
                $db->execute('INSERT INTO t (id) VALUES (1)');
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM t'));
    }

    public function testStrictModeRejectsTruncation(): void
    {
        $this->db->statement('CREATE TABLE t (name VARCHAR(3)) ENGINE=InnoDB');

        $this->expectException(DatabaseException::class);
        $this->db->execute('INSERT INTO t (name) VALUES (?)', ['toolong']);
    }

    public function testErrorsDoNotContainQueryValues(): void
    {
        try {
            $this->db->execute('SELECT * FROM missing_table WHERE secret = ?', ['hunter2']);
            self::fail('Expected exception');
        } catch (DatabaseException $e) {
            self::assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }
}
