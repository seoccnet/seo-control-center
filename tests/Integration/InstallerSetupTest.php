<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Config\EnvFile;
use SeoControlCenter\Core\Config\Environment;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\Install\Installer;
use SeoControlCenter\Core\Install\InstallState;
use SeoControlCenter\Core\Install\Requirements;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Domain\Shared\ValidationException;

/** Real setup against the repository's isolated *_test database, never a customer database. */
final class InstallerSetupTest extends DatabaseTestCase
{
    private string $directory;
    /** @var list<Connection> */
    private array $connections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/seocc-setup-' . bin2hex(random_bytes(8));
        foreach (['keys', 'logs', 'cache', 'tmp'] as $folder) {
            mkdir($this->directory . '/storage/' . $folder, 0o700, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }
        if (isset($this->directory) && is_dir($this->directory)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testCompleteInstallationSavesCronCreatesAccountAndLocksSetup(): void
    {
        $state = new InstallState(new Paths($this->directory));
        $result = $this->installer()->install($this->input($state->setupKey()), ClientInfo::cli());
        self::assertSame('owner@example.com', $result['email']);
        $env = EnvFile::load($this->directory . '/.env');
        self::assertSame('15', $env['CRON_INTERVAL_MINUTES']);
        self::assertSame('production', $env['APP_ENV']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM users WHERE email = 'owner@example.com'"));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM workspaces'));
        self::assertTrue($state->isInstalled());
        self::assertFileExists($this->directory . '/storage/keys/keyring.php');
        self::assertFileDoesNotExist($state->setupKeyFile());
    }

    public function testAnUnrelatedExistingTableStopsBeforeConfigurationAndPreservesItsData(): void
    {
        $this->db->statement('CREATE TABLE existing_website (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->db->execute('INSERT INTO existing_website VALUES (42)');
        $state = new InstallState(new Paths($this->directory));
        try {
            $this->installer()->install($this->input($state->setupKey()), ClientInfo::cli());
            self::fail('A non-empty database must not be installed into.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('db_name', $exception->errors());
            self::assertFileDoesNotExist($this->directory . '/.env');
            self::assertSame(42, (int) $this->db->fetchValue('SELECT id FROM existing_website'));
        }
    }

    private function installer(): Installer
    {
        $paths = new Paths($this->directory);
        return new Installer($paths, new InstallState($paths), new Requirements($paths), function (string $root): Container {
            /** @var Closure(string, Environment): Container $bootstrap */
            $bootstrap = require dirname(__DIR__, 2) . '/bootstrap/app.php';
            $env = new Environment([...EnvFile::load($root . '/.env'), 'APP_KEYRING_PATH' => $root . '/storage/keys/keyring.php']);
            $app = $bootstrap(dirname(__DIR__, 2), $env);
            $app->instance(LoggerInterface::class, new NullLogger());
            // Resolve source migration paths, then put all generated runtime files in the fixture.
            $app->make(Migrator::class);
            $app->instance(Paths::class, new Paths($root));
            $this->connections[] = $app->make(Connection::class);
            return $app;
        });
    }

    /** @return array<string, string> */
    private function input(string $key): array
    {
        $config = self::testConfig();
        self::assertNotNull($config);
        return [
            'setup_key' => $key, 'app_url' => 'https://seo.example.com',
            'db_host' => $config->host, 'db_port' => (string) $config->port,
            'db_name' => $config->database, 'db_user' => $config->username, 'db_password' => $config->password,
            'admin_name' => 'Demo Owner', 'admin_email' => 'owner@example.com',
            'admin_password' => 'correct horse battery staple', 'admin_password_confirm' => 'correct horse battery staple',
            'workspace' => 'Example Agency', 'cron_interval' => '15',
        ];
    }
}
