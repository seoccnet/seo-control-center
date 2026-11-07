<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Update\MaintenanceMode;
use SeoControlCenter\Core\Update\ReleaseBuilder;
use SeoControlCenter\Core\Update\ReleasePackage;
use SeoControlCenter\Core\Update\UpdateException;
use SeoControlCenter\Core\Update\UpdateInstaller;
use SeoControlCenter\Core\Update\UpdateLock;

/**
 * UPDATES.md -> Update workflow: changed files replaced, removed files
 * deleted, local data untouched, migrations run, failures recorded.
 */
final class UpdateInstallerTest extends TestCase
{
    private string $dir;

    private string $app;

    /** @var array{public: string, secret: string} */
    private array $keys;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scc-installer-test-' . bin2hex(random_bytes(4));
        $this->app = $this->dir . '/app';
        $this->keys = ReleaseBuilder::generateKeyPair();
        foreach (UpdateFixtures::minimalFiles('0.8.0') + [
            'app/Removed.php' => '<?php // gone in 0.9',
            '.env' => 'APP_KEY=keep-me',
            'storage/keys/k1' => 'key material',
            'public/uploads/logo.png' => 'user file',
        ] as $path => $content) {
            UpdateFixtures::write($this->app . '/' . $path, $content);
        }
    }

    protected function tearDown(): void
    {
        UpdateFixtures::removeTree($this->dir);
    }

    public function testInstallReplacesChangedFilesRemovesLeftoversAndKeepsLocalData(): void
    {
        $files = UpdateFixtures::minimalFiles('0.9.0') + ['app/New.php' => '<?php // new'];
        $zip = UpdateFixtures::signedZip($this->dir . '/pkg.zip', $files, '0.9.0', $this->keys['secret']);
        $manifest = ReleasePackage::verify($zip, $this->keys['public'], '0.8.0');
        $migrated = false;
        $maintenance = new MaintenanceMode($this->app);
        $installer = new UpdateInstaller($this->app, null, $maintenance, function () use (&$migrated, $maintenance): array {
            $migrated = true;
            self::assertTrue($maintenance->isEnabled(), 'Migrations run in maintenance mode.');

            return ['2026_09_27_000019_create_update_history_table'];
        });

        $result = $installer->install($zip, $manifest);

        self::assertTrue($migrated);
        self::assertSame('0.9.0', $result['version']);
        self::assertSame(3, $result['changed'], 'version.json, app/Hello.php and app/New.php; unchanged files are skipped.');
        self::assertSame(1, $result['removed']);
        self::assertSame(['2026_09_27_000019_create_update_history_table'], $result['migrations']);
        self::assertFileDoesNotExist($this->app . '/app/Removed.php');
        self::assertSame('<?php // new', file_get_contents($this->app . '/app/New.php'));
        self::assertSame('APP_KEY=keep-me', file_get_contents($this->app . '/.env'));
        self::assertFileExists($this->app . '/storage/keys/k1');
        self::assertFileExists($this->app . '/public/uploads/logo.png', 'public/ is not pruned.');
        self::assertFalse($maintenance->isEnabled());
        self::assertDirectoryDoesNotExist($this->app . '/storage/updates/staging-0.9.0');
        self::assertSame('completed', $installer->lastState()['status'] ?? null);
    }

    public function testFailedMigrationKeepsMaintenanceModeAndRecordsTheStep(): void
    {
        $zip = UpdateFixtures::signedZip($this->dir . '/pkg.zip', UpdateFixtures::minimalFiles('0.9.0'), '0.9.0', $this->keys['secret']);
        $manifest = ReleasePackage::verify($zip, $this->keys['public'], '0.8.0');
        $maintenance = new MaintenanceMode($this->app);
        $installer = new UpdateInstaller($this->app, null, $maintenance, static function (): array {
            throw new \RuntimeException('table exists');
        });

        try {
            $installer->install($zip, $manifest);
            self::fail('Failure not reported.');
        } catch (UpdateException $e) {
            self::assertStringContainsString('migrate', $e->getMessage());
        }

        self::assertTrue($maintenance->isEnabled(), 'Files were already replaced, so the site stays in maintenance mode.');
        $state = $installer->lastState();
        self::assertSame('failed', $state['status'] ?? null);
        self::assertSame('migrate', $state['step'] ?? null);

        // Installing the same package again is the repair path.
        $retry = new UpdateInstaller($this->app, null, $maintenance, static fn(): array => []);
        $result = $retry->install($zip, $manifest);
        self::assertSame(0, $result['changed']);
        self::assertFalse($maintenance->isEnabled());
    }

    public function testMaintenanceModeAllowsOnlyRecoveryPaths(): void
    {
        self::assertTrue(MaintenanceMode::allows('/login'));
        self::assertTrue(MaintenanceMode::allows('/system/updates/install'));
        self::assertTrue(MaintenanceMode::allows('/assets/app.css'));
        self::assertFalse(MaintenanceMode::allows('/'));
        self::assertFalse(MaintenanceMode::allows('/mcp'));
        self::assertFalse(MaintenanceMode::allows('/projects/1'));
        self::assertFalse(MaintenanceMode::allows('/login-extra'));
        self::assertFalse(MaintenanceMode::allows('/system/updates-other'));
    }

    public function testRecoveryVerifiesSamePackageAfterVersionFileWasCopied(): void
    {
        $zip = UpdateFixtures::signedZip($this->dir . '/pkg.zip', UpdateFixtures::minimalFiles('0.9.0'), '0.9.0', $this->keys['secret']);
        $manifest = ReleasePackage::verify($zip, $this->keys['public'], '0.8.0');
        $installer = new UpdateInstaller($this->app, null, new MaintenanceMode($this->app), static function (): array {
            throw new \RuntimeException('Interrupted migration');
        });
        try {
            $installer->install($zip, $manifest);
            self::fail('Expected migration failure.');
        } catch (UpdateException) {
        }
        self::assertSame('0.9.0', json_decode((string) file_get_contents($this->app . '/version.json'), true)['version']);
        self::assertSame('0.9.0', $installer->verifyPackage($zip, $this->keys['public'], '0.9.0')->version);
        self::assertSame('0.8.0', $installer->lastState()['from_version'] ?? null);

        $other = UpdateFixtures::signedZip($this->dir . '/other.zip', UpdateFixtures::minimalFiles('0.9.0') + ['app/Different.php' => '<?php'], '0.9.0', $this->keys['secret']);
        $this->expectException(UpdateException::class);
        $installer->verifyPackage($other, $this->keys['public'], '0.9.0');
    }

    public function testFailedRetryExtractionPreservesMaintenanceAndRecoveryIdentity(): void
    {
        $zip = UpdateFixtures::signedZip($this->dir . '/pkg.zip', UpdateFixtures::minimalFiles('0.9.0'), '0.9.0', $this->keys['secret']);
        $manifest = ReleasePackage::verify($zip, $this->keys['public'], '0.8.0');
        $maintenance = new MaintenanceMode($this->app);
        $installer = new UpdateInstaller($this->app, null, $maintenance, static function (): array {
            throw new \RuntimeException('Interrupted migration');
        });
        try {
            $installer->install($zip, $manifest);
        } catch (UpdateException) {
        }
        $hash = $installer->lastState()['manifest_hash'] ?? null;
        file_put_contents($zip, 'damaged');
        try {
            $installer->install($zip, $manifest);
            self::fail('Expected extraction failure.');
        } catch (UpdateException) {
        }
        self::assertTrue($maintenance->isEnabled());
        self::assertSame('extract', $installer->lastState()['step'] ?? null);
        self::assertTrue($installer->lastState()['applying'] ?? false);
        self::assertSame($hash, $installer->lastState()['manifest_hash'] ?? null);
    }

    public function testPreexistingMaintenanceSurvivesPreparationFailure(): void
    {
        $zip = UpdateFixtures::signedZip($this->dir . '/pkg.zip', UpdateFixtures::minimalFiles('0.9.0'), '0.9.0', $this->keys['secret']);
        $manifest = ReleasePackage::verify($zip, $this->keys['public'], '0.8.0');
        $maintenance = new MaintenanceMode($this->app);
        $maintenance->enable('Manual maintenance');
        $installer = new UpdateInstaller($this->app, null, $maintenance, static fn(): array => []);
        file_put_contents($zip, 'damaged');
        try {
            $installer->install($zip, $manifest);
            self::fail('Expected extraction failure.');
        } catch (UpdateException) {
        }
        self::assertTrue($maintenance->isEnabled());
        self::assertFileExists($this->app . '/app/Removed.php');
    }

    public function testConcurrentUpdateCannotChangeRecoveryState(): void
    {
        $zip = UpdateFixtures::signedZip($this->dir . '/pkg.zip', UpdateFixtures::minimalFiles('0.9.0'), '0.9.0', $this->keys['secret']);
        $manifest = ReleasePackage::verify($zip, $this->keys['public'], '0.8.0');
        UpdateFixtures::write($this->app . '/storage/updates/state.json', '{"status":"running"}');
        $handle = fopen($this->app . '/storage/updates/update.lock', 'c');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            $installer = new UpdateInstaller($this->app, null, new MaintenanceMode($this->app), static fn(): array => []);
            try {
                $installer->install($zip, $manifest);
                self::fail('Concurrent install must be refused.');
            } catch (UpdateException $e) {
                self::assertStringContainsString('in progress', $e->getMessage());
            }
            self::assertSame('{"status":"running"}', file_get_contents($this->app . '/storage/updates/state.json'));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        self::assertSame('nested', UpdateLock::run($this->app, fn(): string => UpdateLock::run($this->app, static fn(): string => 'nested')));
    }
}
