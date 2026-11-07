<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use Psr\Log\NullLogger;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Database\Migrations\MigrationException;
use SeoControlCenter\Core\Database\Migrations\Migrator;

final class MigratorTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/scc-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testAppliesPendingMigrationsOnceInOrder(): void
    {
        $this->writeMigration('2026_01_01_000002_create_b', 'CREATE TABLE b (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->writeMigration('2026_01_01_000001_create_a', 'CREATE TABLE a (id INT PRIMARY KEY) ENGINE=InnoDB');
        $migrator = $this->migrator();

        self::assertSame(['2026_01_01_000001_create_a', '2026_01_01_000002_create_b'], $migrator->migrate());
        self::assertSame([], $migrator->migrate(), 'Second run must be a no-op.');
        self::assertSame(1, (int) $this->db->fetchValue('SELECT MAX(batch) FROM migrations'));
    }

    public function testStatusDetectsModifiedMigration(): void
    {
        $this->writeMigration('2026_01_01_000001_create_a', 'CREATE TABLE a (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->migrator()->migrate();
        $this->writeMigration('2026_01_01_000001_create_a', 'CREATE TABLE a (id BIGINT PRIMARY KEY) ENGINE=InnoDB');

        $status = $this->migrator()->status();
        self::assertTrue($status[0]->modifiedAfterApply);
    }

    public function testFailedMigrationIsNotRecordedAndStopsTheRun(): void
    {
        $this->writeMigration('2026_01_01_000001_ok', 'CREATE TABLE a (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->writeMigration('2026_01_01_000002_broken', 'CREATE TABLE broken (');
        $this->writeMigration('2026_01_01_000003_never', 'CREATE TABLE c (id INT PRIMARY KEY) ENGINE=InnoDB');

        try {
            $this->migrator()->migrate();
            self::fail('Expected failure');
        } catch (MigrationException) {
        }

        $applied = array_column($this->db->fetchAll('SELECT migration FROM migrations'), 'migration');
        self::assertSame(['2026_01_01_000001_ok'], $applied);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM locks WHERE name = 'migrations'"), 'Lock must be released.');
    }

    public function testConcurrentRunIsRefused(): void
    {
        $this->writeMigration('2026_01_01_000001_create_a', 'CREATE TABLE a (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->db->statement(LeaseLock::createTableSql());
        $other = new LeaseLock($this->newConnection(), 'migrations', 60);
        self::assertTrue($other->acquire());

        $this->expectException(MigrationException::class);
        $this->migrator()->migrate();
    }

    public function testRejectsBadFileNames(): void
    {
        file_put_contents($this->dir . '/create_things.php', '<?php return null;');

        $this->expectException(MigrationException::class);
        $this->migrator()->discover();
    }

    public function testProjectMigrationsApplyCleanly(): void
    {
        $migrator = new Migrator($this->db, [dirname(__DIR__, 2) . '/database/migrations'], new NullLogger());
        $migrator->migrate();

        self::assertSame([], $migrator->pending());
    }

    public function testEveryProjectMigrationCanResumeBeforeItsCompletionWasRecorded(): void
    {
        $migrator = new Migrator($this->db, [dirname(__DIR__, 2) . '/database/migrations'], new NullLogger());
        foreach ($migrator->discover() as $file) {
            $file->load()->up($this->db);
            $beforeRoles = (int) $this->db->fetchValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles'") > 0
                ? (int) $this->db->fetchValue('SELECT COUNT(*) FROM roles') : 0;
            $file->load()->up($this->db);
            if ($beforeRoles > 0) {
                self::assertSame($beforeRoles, (int) $this->db->fetchValue('SELECT COUNT(*) FROM roles'), $file->name . ' duplicated roles on retry.');
            }
        }
        $id = $this->db->fetchValue("SELECT value FROM system_state WHERE name = 'installation_id'");
        $migrator->migrate();
        self::assertSame($id, $this->db->fetchValue("SELECT value FROM system_state WHERE name = 'installation_id'"));
        self::assertSame([], $migrator->pending());
    }

    public function testRejectsChangedAppliedFileBeforeExecutingPendingMigrations(): void
    {
        $this->writeMigration('2026_01_01_000001_create_a', 'CREATE TABLE a (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->migrator()->migrate();
        $this->writeMigration('2026_01_01_000001_create_a', 'CREATE TABLE a (id BIGINT PRIMARY KEY) ENGINE=InnoDB');
        $this->writeMigration('2026_01_01_000002_create_b', 'CREATE TABLE b (id INT PRIMARY KEY) ENGINE=InnoDB');
        try {
            $this->migrator()->migrate();
            self::fail('Modified history must stop the run.');
        } catch (MigrationException $e) {
            self::assertStringContainsString('modified or removed', $e->getMessage());
        }
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'b'"));
    }

    public function testSessionLockPreventsTakeoverEvenWhenTheLeaseExpired(): void
    {
        $name = 'scc.migrations.' . substr(hash('sha256', (string) $this->db->fetchValue('SELECT DATABASE()')), 0, 40);
        $other = $this->newConnection();
        self::assertSame(1, (int) $other->fetchValue('SELECT GET_LOCK(?, 0)', [$name]));
        try {
            $this->migrator()->migrate();
            self::fail('The session lock must prevent takeover.');
        } catch (MigrationException $e) {
            self::assertStringContainsString('in progress', $e->getMessage());
        } finally {
            $other->fetchValue('SELECT RELEASE_LOCK(?)', [$name]);
            $other->disconnect();
        }
        self::assertSame([], $this->migrator()->migrate());
    }

    public function testReleasedChecksumsRemainRecognizedWithoutRerunningAppliedMigrations(): void
    {
        $migrator = new Migrator($this->db, [dirname(__DIR__, 2) . '/database/migrations'], new NullLogger());
        $migrator->migrate();
        $legacy = json_decode((string) file_get_contents(dirname(__DIR__) . '/Fixtures/migrations/released-checksums.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($legacy as $name => $checksum) {
            $this->db->execute('UPDATE migrations SET checksum = ? WHERE migration = ?', [$checksum, $name]);
        }
        foreach ($migrator->status() as $status) {
            self::assertFalse($status->modifiedAfterApply, $status->name . ' lost its released checksum compatibility.');
        }
        self::assertSame([], $migrator->migrate());
    }

    public function testInterruptedColumnAdditionStillCreatesItsMissingIndex(): void
    {
        $migrator = new Migrator($this->db, [dirname(__DIR__, 2) . '/database/migrations'], new NullLogger());
        foreach ($migrator->discover() as $file) {
            if ($file->name === '2026_10_02_000022_add_deleted_at_to_workspaces') {
                $this->db->statement('ALTER TABLE workspaces ADD COLUMN deleted_at DATETIME NULL AFTER updated_at');
                $file->load()->up($this->db);
                $file->load()->up($this->db);
                self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspaces' AND INDEX_NAME = 'workspaces_deleted'"));
                return;
            }
            $file->load()->up($this->db);
        }
        self::fail('Expected workspace migration missing.');
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->db, [$this->dir], new NullLogger());
    }

    private function writeMigration(string $name, string $sql): void
    {
        $code = sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            return new class () implements SeoControlCenter\Core\Database\Migrations\Migration {
                public function up(SeoControlCenter\Core\Database\Connection $db): void
                {
                    $db->statement(%s);
                }
            };
            PHP, var_export($sql, true));
        file_put_contents($this->dir . '/' . $name . '.php', $code);
        // Rewriting the same path in one test needs a fresh opcache view.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->dir . '/' . $name . '.php', true);
        }
    }
}
