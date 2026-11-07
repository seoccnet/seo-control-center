<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Config\EnvFile;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\DatabaseConfig;

/**
 * Base class for tests against a real MariaDB/MySQL database.
 *
 * Configure TEST_DB_* in the environment or in .env.testing. The database
 * name must end in "_test" because every table in it is dropped between tests.
 * Tests are skipped when no test database is configured.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Connection $db;

    protected function setUp(): void
    {
        $config = self::testConfig();
        if ($config === null) {
            self::markTestSkipped('No test database configured (set TEST_DB_DATABASE in .env.testing).');
        }

        $this->db = new Connection($config);
        $this->dropAllTables();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->dropAllTables();
            $this->db->disconnect();
        }
    }

    protected function newConnection(): Connection
    {
        $config = self::testConfig();
        self::assertNotNull($config);

        return new Connection($config);
    }

    private function dropAllTables(): void
    {
        $tables = $this->db->fetchAll(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        );
        $this->db->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->db->statement(sprintf('DROP TABLE IF EXISTS `%s`', str_replace('`', '``', (string) $table['name'])));
        }
        $this->db->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected static function testConfig(): ?DatabaseConfig
    {
        $file = EnvFile::load(dirname(__DIR__, 2) . '/.env.testing');
        $value = static fn(string $key, string $default = ''): string => (getenv($key) !== false ? (string) getenv($key) : null) ?? $file[$key] ?? $default;

        $database = $value('TEST_DB_DATABASE');
        if ($database === '') {
            return null;
        }
        if (!str_ends_with($database, '_test')) {
            throw new \RuntimeException('TEST_DB_DATABASE must end with "_test": integration tests drop every table in it.');
        }

        return new DatabaseConfig(
            $value('TEST_DB_HOST', '127.0.0.1'),
            (int) $value('TEST_DB_PORT', '3306'),
            $database,
            $value('TEST_DB_USERNAME', 'root'),
            $value('TEST_DB_PASSWORD'),
        );
    }
}
