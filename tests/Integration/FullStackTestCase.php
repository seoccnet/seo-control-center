<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Config\Environment;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Accounts\UserProvisioning;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Tests\Support\Browser;

/**
 * The real application wired to the test database: real sessions, rate
 * limits, audit log, repositories and seeded roles. Migrations run first.
 */
abstract class FullStackTestCase extends DatabaseTestCase
{
    public const string PASSWORD = 'correct horse battery staple';

    protected Container $app;

    protected function setUp(): void
    {
        parent::setUp();
        $config = self::testConfig();
        self::assertNotNull($config);

        /** @var Closure(string, Environment): Container $bootstrap */
        $bootstrap = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $this->app = $bootstrap(dirname(__DIR__, 2), new Environment([
            'APP_ENV' => 'testing',
            'APP_URL' => 'http://scc.test',
            'DB_HOST' => $config->host,
            'DB_PORT' => (string) $config->port,
            'DB_DATABASE' => $config->database,
            'DB_USERNAME' => $config->username,
            'DB_PASSWORD' => $config->password,
            'APP_KEYS' => '1:' . base64_encode(str_repeat("\x42", 32)),
        ]));
        $this->app->instance(LoggerInterface::class, new NullLogger());
        $this->app->make(Migrator::class)->migrate();
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $this->app->make(Connection::class)->disconnect();
        }
        parent::tearDown();
    }

    /**
     * @return array{user: User, workspace: ?Workspace}
     */
    protected function createUser(string $email, ?string $workspace = null): array
    {
        return $this->app->make(UserProvisioning::class)->createUser($email, ucfirst(strtok($email, '@') ?: 'x'), self::PASSWORD, false, $workspace, ActorType::Cli, ClientInfo::cli());
    }

    protected function addMember(Workspace $workspace, User $user, string $roleKey): void
    {
        $this->db->execute(
            "INSERT INTO workspace_users (workspace_id, user_id, role_id, status, created_at)
             SELECT ?, ?, id, 'active', UTC_TIMESTAMP() FROM roles WHERE workspace_id IS NULL AND role_key = ?",
            [$workspace->id, $user->id, $roleKey],
        );
    }

    protected function browserFor(string $email): Browser
    {
        $browser = new Browser($this->app);
        $response = $browser->login($email, self::PASSWORD);
        self::assertSame(303, $response->getStatusCode(), 'login failed for ' . $email);

        return $browser;
    }

    protected function createProject(Browser $browser, string $name, string $domain): string
    {
        $browser->get('/projects/new');
        $response = $browser->post('/projects', ['name' => $name, 'primary_domain' => $domain, 'timezone' => 'UTC']);
        self::assertSame(303, $response->getStatusCode());

        return substr($response->getHeaderLine('Location'), strlen('/projects/'));
    }
}
