<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use DateTimeImmutable;
use SeoControlCenter\Core\Backup\DatabaseBackup;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Core\Support\SystemClock;
use SeoControlCenter\Core\Update\MaintenanceMode;
use SeoControlCenter\Core\Update\ReleaseBuilder;
use SeoControlCenter\Core\Update\ReleasePackage;
use SeoControlCenter\Core\Update\UpdateException;
use SeoControlCenter\Core\Update\UpdateInstaller;
use SeoControlCenter\Domain\Integrations\OAuthClientCredentials;
use SeoControlCenter\Domain\Integrations\OAuthClientRepository;
use SeoControlCenter\Tests\Unit\Update\UpdateFixtures;

/**
 * A backup restores the database byte for byte: binary (encrypted) values,
 * Unicode, quotes, newlines, NULLs and many rows.
 */
final class DatabaseBackupTest extends FullStackTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/scc-backup-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testBackupAndRestoreRoundTrip(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme «Ünïcödé» ✓');
        self::assertNotNull($acme['workspace']);
        $this->app->make(OAuthClientRepository::class)->save('google', new OAuthClientCredentials('1-a.apps.googleusercontent.com', "secret with 'quotes', \"double\", \\ backslash;\nand a newline"), $acme['user']->id);
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at, deleted_at)
             VALUES ('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fb1', ?, 'Line one;\nline two', 'acme.test', 'Europe/Berlin', '2026-09-26 10:00:00', '2026-09-26 10:00:00', NULL)",
            [$acme['workspace']->id],
        );
        for ($i = 0; $i < 450; ++$i) { // more than two INSERT batches
            $this->db->execute(
                'INSERT INTO system_events (event_type, message, context, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
                ['test.event', "Event {$i}: 🚀 'quoted' \\ back", json_encode(['i' => $i], JSON_THROW_ON_ERROR)],
            );
        }
        $before = $this->snapshot();

        $backups = new DatabaseBackup($this->db, new SystemClock(), $this->dir, '0.7.0-test', 2);
        $backup = $backups->create();
        self::assertMatchesRegularExpression(DatabaseBackup::FILE_PATTERN, $backup->name);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('0600', substr(sprintf('%o', fileperms($backup->path)), -4), 'Backups are readable by the owner only.');
        }
        self::assertGreaterThan(1000, $backup->size);
        $sql = (string) gzdecode((string) file_get_contents($backup->path));
        self::assertStringContainsString('-- app 0.7.0-test', $sql);
        self::assertStringNotContainsString('secret with', $sql, 'Secrets stay encrypted in the backup.');

        // Damage the data, then restore.
        $this->db->execute('DELETE FROM system_events');
        $this->db->execute("UPDATE projects SET name = 'changed'");
        $this->db->statement('DROP TABLE oauth_clients');
        $count = $backups->restore($backup);

        self::assertGreaterThan(20, $count);
        self::assertSame($before, $this->snapshot(), 'Every row is restored exactly.');
        self::assertSame("secret with 'quotes', \"double\", \\ backslash;\nand a newline", $this->app->make(OAuthClientRepository::class)->get('google')?->clientSecret, 'Encrypted values survive byte for byte.');
    }

    public function testRetentionAndSafeNames(): void
    {
        $backups = new DatabaseBackup($this->db, new SystemClock(), $this->dir, 'test', 2);
        mkdir($this->dir);
        foreach (['20260901-010000', '20260902-010000', '20260903-010000'] as $stamp) {
            file_put_contents($this->dir . '/scc-backup-' . $stamp . '.sql.gz', 'old');
        }
        $new = $backups->create();

        self::assertSame([$new->name, 'scc-backup-20260903-010000.sql.gz'], array_map(static fn($b): string => $b->name, $backups->list()), 'Only the newest two are kept.');
        self::assertNull($backups->find('../.env'));
        self::assertNull($backups->find('scc-backup-20260901-010000.sql.gz'), 'Pruned.');
        self::assertNotNull($backups->find($new->name));
    }

    public function testBackupsWithinTheSameSecondDoNotOverwriteTheRestorePoint(): void
    {
        $backups = new DatabaseBackup($this->db, new FrozenClock(new DateTimeImmutable('2026-10-06 12:00:00 UTC')), $this->dir, 'test', 7);
        $first = $backups->create();
        $before = hash_file('sha256', $first->path);
        $this->db->execute("INSERT INTO system_events (event_type, message, created_at) VALUES ('test', 'new', UTC_TIMESTAMP())");
        $second = $backups->create();
        self::assertNotSame($first->name, $second->name);
        self::assertSame($before, hash_file('sha256', $first->path));
        self::assertCount(2, $backups->list());
    }

    public function testSnapshotExcludesWritesCommittedAfterBackupStarts(): void
    {
        $other = $this->newConnection();
        $clock = new class ($other) implements Clock {
            private int $calls = 0;

            public function __construct(private readonly Connection $other) {}

            public function now(): DateTimeImmutable
            {
                if (++$this->calls === 2) {
                    $this->other->execute("INSERT INTO system_events (event_type, message, created_at) VALUES ('test', 'committed after snapshot', UTC_TIMESTAMP())");
                }
                return new DateTimeImmutable('2026-10-06 12:00:00 UTC');
            }
        };
        try {
            $backup = (new DatabaseBackup($this->db, $clock, $this->dir, 'test', 7))->create();
            self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM system_events WHERE message = 'committed after snapshot'"));
            self::assertStringNotContainsString('committed after snapshot', (string) gzdecode((string) file_get_contents($backup->path)));
            self::assertFalse($this->db->pdo()->inTransaction());
        } finally {
            $other->disconnect();
        }
    }

    public function testRecoveryBackupSurvivesRetentionUntilReleased(): void
    {
        $backups = new DatabaseBackup($this->db, new FrozenClock(new DateTimeImmutable('2026-10-06 12:00:00 UTC')), $this->dir, 'test', 1);
        $original = $backups->create(protectForRecovery: true);
        $backups->create();
        self::assertNotNull($backups->find($original->name));
        $backups->releaseRecoveryBackup($original->name);
        $backups->create();
        self::assertNull($backups->find($original->name));
    }

    public function testIncompleteBackupIsRefusedBeforeDestructiveStatements(): void
    {
        mkdir($this->dir);
        $name = 'scc-backup-20261006-120000.sql.gz';
        file_put_contents($this->dir . '/' . $name, gzencode("DROP TABLE system_events;\n"));
        $backups = new DatabaseBackup($this->db, new SystemClock(), $this->dir, 'test', 7);
        $backup = $backups->find($name);
        self::assertNotNull($backup);
        try {
            $backups->restore($backup);
            self::fail('An incomplete backup must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('No restore statements', $e->getMessage());
        }
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_events'"));
    }

    public function testUpdateRetryReusesTheOriginalProtectedBackup(): void
    {
        $root = sys_get_temp_dir() . '/scc-real-backup-update-' . bin2hex(random_bytes(5));
        try {
            UpdateFixtures::sourceTree($root, '0.8.0');
            $keys = ReleaseBuilder::generateKeyPair();
            $zip = UpdateFixtures::signedZip($root . '/release.zip', UpdateFixtures::minimalFiles('0.9.0'), '0.9.0', $keys['secret']);
            $manifest = ReleasePackage::verify($zip, $keys['public'], '0.8.0');
            $maintenance = new MaintenanceMode($root);
            $clock = new class ($maintenance) implements Clock {
                public function __construct(private readonly MaintenanceMode $maintenance) {}

                public function now(): DateTimeImmutable
                {
                    self::assertMaintenance($this->maintenance);
                    return new DateTimeImmutable('2026-10-06 12:00:00 UTC');
                }

                private static function assertMaintenance(MaintenanceMode $maintenance): void
                {
                    \PHPUnit\Framework\Assert::assertTrue($maintenance->isEnabled(), 'Backup must run after maintenance starts.');
                }
            };
            $backups = new DatabaseBackup($this->db, $clock, $this->dir, '0.8.0', 1);
            $installer = new UpdateInstaller($root, $backups, $maintenance, function (): array {
                $this->db->execute("INSERT INTO system_events (event_type, message, created_at) VALUES ('test', 'partial migration write', UTC_TIMESTAMP())");
                throw new \RuntimeException('Interrupted after a data write');
            });
            try {
                $installer->install($zip, $manifest);
                self::fail('Expected interruption.');
            } catch (UpdateException) {
            }
            $original = $backups->list()[0];
            $hash = hash_file('sha256', $original->path);
            self::assertFileExists($original->path . '.keep');
            self::assertStringNotContainsString('partial migration write', (string) gzdecode((string) file_get_contents($original->path)));
            $retry = new UpdateInstaller($root, $backups, $maintenance, static fn(): array => []);
            $result = $retry->install($zip, $retry->verifyPackage($zip, $keys['public'], '0.9.0'));
            self::assertSame($original->name, $result['backup']);
            self::assertSame($hash, hash_file('sha256', $original->path));
            self::assertCount(1, $backups->list());
            self::assertFileDoesNotExist($original->path . '.keep');
            self::assertFalse($maintenance->isEnabled());
        } finally {
            UpdateFixtures::removeTree($root);
        }
    }

    public function testDamagedGzipWithACompletionFooterCannotStartRestoring(): void
    {
        mkdir($this->dir);
        $backups = new DatabaseBackup($this->db, new SystemClock(), $this->dir, 'test', 7);
        $gzip = gzencode("DROP TABLE system_events;\n-- end of backup\n");
        self::assertIsString($gzip);
        $badCrc = $gzip;
        $badCrc[strlen($badCrc) - 8] = chr(ord($badCrc[strlen($badCrc) - 8]) ^ 0xff);
        foreach ([$badCrc, substr($gzip, 0, -4)] as $damaged) {
            $name = 'scc-backup-20261006-120000.sql.gz';
            file_put_contents($this->dir . '/' . $name, $damaged);
            $backup = $backups->find($name);
            self::assertNotNull($backup);
            try {
                $backups->restore($backup);
                self::fail('Damaged gzip must be refused.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('No restore statements', $e->getMessage());
            }
            self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_events'"));
        }
    }

    /**
     * @return array<string, string> table => hash of all rows
     */
    private function snapshot(): array
    {
        $hashes = [];
        foreach (['users', 'workspaces', 'workspace_users', 'projects', 'oauth_clients', 'system_events', 'migrations'] as $table) {
            $rows = $this->db->fetchAll(sprintf('SELECT * FROM `%s` ORDER BY 1', $table));
            $hashes[$table] = hash('sha256', serialize($rows));
        }

        return $hashes;
    }
}
