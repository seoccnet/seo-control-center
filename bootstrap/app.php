<?php

declare(strict_types=1);

/*
 * Composition root: builds the container with explicit wiring (ADR-016).
 * Used by public/index.php, bin/console and tests.
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Audit\PdoAuditLogger;
use SeoControlCenter\Core\Backup\DatabaseBackup;
use SeoControlCenter\Core\Branding\Branding;
use SeoControlCenter\Core\Branding\BrandingRepository;
use SeoControlCenter\Core\Branding\CurrentBranding;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Config\ConfigException;
use SeoControlCenter\Core\Config\Environment;
use SeoControlCenter\Core\Console\Commands\BackupCommand;
use SeoControlCenter\Core\Console\Commands\CronRunCommand;
use SeoControlCenter\Core\Console\Commands\CryptoKeyGenerateCommand;
use SeoControlCenter\Core\Console\Commands\CryptoRotateCommand;
use SeoControlCenter\Core\Console\Commands\DbCheckCommand;
use SeoControlCenter\Core\Console\Commands\MakeMigrationCommand;
use SeoControlCenter\Core\Console\Commands\MigrateCommand;
use SeoControlCenter\Core\Console\Commands\MigrateStatusCommand;
use SeoControlCenter\Core\Console\Commands\QueueStatusCommand;
use SeoControlCenter\Core\Console\Commands\ReleaseCommand;
use SeoControlCenter\Core\Console\ConsoleApplication;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Crypto\EncryptedColumn;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Crypto\KeyRing;
use SeoControlCenter\Core\Crypto\KeyRingLoader;
use SeoControlCenter\Core\Crypto\SecretRotator;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\DatabaseConfig;
use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\Errors\PhpErrorHandler;
use SeoControlCenter\Core\Features\ConfiguredDisabledFeatures;
use SeoControlCenter\Core\Features\DisabledFeatures;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Client\CachingHostResolver;
use SeoControlCenter\Core\Http\Client\CurlHttpClient;
use SeoControlCenter\Core\Http\Client\HostResolver;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\SystemHostResolver;
use SeoControlCenter\Core\Http\ErrorResponder;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Http\Middleware\ErrorHandlerMiddleware;
use SeoControlCenter\Core\Http\Middleware\RequestIdMiddleware;
use SeoControlCenter\Core\Http\Middleware\SecurityHeadersMiddleware;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\RouteDispatcher;
use SeoControlCenter\Core\Http\Routing\FastRouteRouter;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Http\Routing\Router;
use SeoControlCenter\Core\Install\Installer;
use SeoControlCenter\Core\Install\InstallerHandler;
use SeoControlCenter\Core\Install\InstallState;
use SeoControlCenter\Core\Install\Requirements;
use SeoControlCenter\Core\Logging\JsonLineLogger;
use SeoControlCenter\Core\Logging\LogContext;
use SeoControlCenter\Core\Logging\Redactor;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\CronRunner;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Queue\Worker;
use SeoControlCenter\Core\RateLimit\PdoRateLimiter;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Security\CsrfMiddleware;
use SeoControlCenter\Core\Security\PasswordHasher;
use SeoControlCenter\Core\Session\PdoSessionStore;
use SeoControlCenter\Core\Session\SessionConfig;
use SeoControlCenter\Core\Session\SessionMiddleware;
use SeoControlCenter\Core\Session\SessionStore;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Core\Support\SystemClock;
use SeoControlCenter\Core\Support\SystemState;
use SeoControlCenter\Core\View\LayoutWidgets;
use SeoControlCenter\Core\View\Template;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\MfaService;
use SeoControlCenter\Domain\Accounts\UserProvisioning;
use SeoControlCenter\Modules\Accounts\Console\UserCreateCommand;
use SeoControlCenter\Modules\Accounts\Http\AuthenticationMiddleware;
use SeoControlCenter\Modules\Assistant\Persistence\PdoAssistantSettingsRepository;
use SeoControlCenter\Modules\Bing\Persistence\PdoBingAccountRepository;
use SeoControlCenter\Modules\DataForSEO\Persistence\PdoDataForSeoProfileRepository;
use SeoControlCenter\Modules\Mail\Persistence\PdoMailSettingsRepository;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleAccessTokens;
use SeoControlCenter\Modules\SearchConsole\Persistence\PdoOAuthClientRepository;
use SeoControlCenter\Modules\Workspaces\Http\WorkspaceContextMiddleware;

return static function (string $basePath, ?Environment $environment = null): Container {
    $container = new Container();
    $paths = new Paths($basePath);
    $env = $environment ?? Environment::fromFile($basePath . '/.env');
    $config = Config::fromDirectory($paths->config(), $env);

    $container->instance(Container::class, $container);
    $container->instance(Paths::class, $paths);
    $container->instance(Environment::class, $env);
    $container->instance(Config::class, $config);

    // --- Version ---------------------------------------------------------
    $versionData = json_decode((string) @file_get_contents($basePath . '/version.json'), true);
    $version = is_array($versionData) && is_string($versionData['version'] ?? null) ? $versionData['version'] : 'unknown';
    $container->instance('app.version', $version);

    // --- Support ---------------------------------------------------------
    $container->singleton(Clock::class, static fn(): Clock => new SystemClock());
    $container->singleton(LogContext::class, static fn(): LogContext => new LogContext());
    $container->singleton(Redactor::class, static function (Container $c): Redactor {
        /** @var list<string> $keys */
        $keys = $c->make(Config::class)->array('logging.redact_keys');

        return new Redactor($keys);
    });
    $container->singleton(LoggerInterface::class, static fn(Container $c): LoggerInterface => new JsonLineLogger(
        $c->make(Paths::class)->storage('logs'),
        $c->make(Redactor::class),
        $c->make(LogContext::class),
        $c->make(Clock::class),
        $c->make(Config::class)->string('logging.level'),
    ));
    $container->singleton(PhpErrorHandler::class, static fn(Container $c): PhpErrorHandler => new PhpErrorHandler(
        $c->make(LoggerInterface::class),
        PHP_SAPI === 'cli',
    ));

    // --- HTTP ------------------------------------------------------------
    $container->singleton(Psr17Factory::class, static fn(): Psr17Factory => new Psr17Factory());
    $container->singleton(ResponseFactory::class, static fn(Container $c): ResponseFactory => new ResponseFactory(
        $c->make(Psr17Factory::class),
        $c->make(Psr17Factory::class),
    ));
    $container->singleton(CurrentBranding::class, static fn(Container $c): CurrentBranding => new CurrentBranding(
        static fn(): Branding => $c->has(BrandingRepository::class) ? $c->make(BrandingRepository::class)->get() : Branding::none(),
    ));
    $container->singleton(LayoutWidgets::class, static fn(Container $c): LayoutWidgets => new LayoutWidgets($c->make(LoggerInterface::class)));
    $container->singleton(ViewRenderer::class, static fn(Container $c): ViewRenderer => new ViewRenderer(
        $c->make(Paths::class)->views(),
        [
            'appName' => $c->make(Config::class)->string('app.name'),
            'appVersion' => $c->get('app.version'),
            // Report a bug / community links in the sidebar.
            'support' => ['email' => $c->make(Config::class)->string('app.support_email'), 'community' => $c->make(Config::class)->string('app.community_url')],
            // Changes whenever a stylesheet or script is updated, so browsers never use stale cached assets.
            'assetVersion' => (string) max(0, ...array_map(
                static fn(string $file): int => (int) @filemtime($file),
                [...(glob($c->make(Paths::class)->base . '/public/assets/css/*.css') ?: []), ...(glob($c->make(Paths::class)->base . '/public/assets/js/*.js') ?: [])],
            )),
            // White label (System → Branding), read lazily on first use.
            'brand' => static fn(): Branding => $c->make(CurrentBranding::class)->get(),
            // The sidebar's source code link (AGPL-3.0 section 13), also on white-label installations.
            'sourceUrl' => $c->make(Config::class)->string('app.source_url'),
            // Module widgets in the signed-in layout (top bar, floating), rendered per request.
            'widgets' => static fn(string $slot, Template $view): string => $c->make(LayoutWidgets::class)->render($slot, $view),
        ],
    ));
    $container->singleton(ErrorResponder::class, static fn(Container $c): ErrorResponder => new ErrorResponder(
        $c->make(ResponseFactory::class),
        $c->make(ViewRenderer::class),
        $c->make(Config::class)->bool('app.debug'),
    ));
    $container->singleton(RouteCollection::class, static fn(): RouteCollection => new RouteCollection());
    $container->singleton(Router::class, static fn(Container $c): Router => new FastRouteRouter($c->make(RouteCollection::class)));
    $container->singleton(RouteDispatcher::class, static fn(Container $c): RouteDispatcher => new RouteDispatcher(
        $c->make(Router::class),
        $c,
        $c->make(ResponseFactory::class),
    ));
    $container->singleton(Kernel::class, static fn(Container $c): Kernel => new Kernel(
        [
            new RequestIdMiddleware($c->make(LogContext::class)),
            new SecurityHeadersMiddleware(
                $c->make(Config::class)->bool('security.hsts.enabled'),
                $c->make(Config::class)->int('security.hsts.max_age'),
                // OAuth providers the "connect" forms redirect to (INTEGRATIONS.md), and the AI apps
                // the MCP sign-in sends the user back to (MCP.md).
                ['https://accounts.google.com', ...array_values(array_map(static fn(mixed $host): string => 'https://' . (is_string($host) ? $host : ''), $c->make(Config::class)->array('mcp.oauth.redirect_hosts')))],
            ),
            new ErrorHandlerMiddleware($c->make(ErrorResponder::class), $c->make(LoggerInterface::class)),
            new SessionMiddleware($c->make(SessionStore::class), $c->make(SessionConfig::class)),
            new CsrfMiddleware($c->make(Config::class)->string('app.url')),
            $c->make(AuthenticationMiddleware::class),
            $c->make(WorkspaceContextMiddleware::class),
        ],
        $c->make(RouteDispatcher::class),
    ));

    // --- Web installer (hosts without SSH) --------------------------------
    $container->singleton(InstallState::class, static fn(Container $c): InstallState => new InstallState($c->make(Paths::class)));
    $container->singleton(Requirements::class, static fn(Container $c): Requirements => new Requirements($c->make(Paths::class)));
    $container->singleton(Installer::class, static fn(Container $c): Installer => new Installer(
        $c->make(Paths::class),
        $c->make(InstallState::class),
        $c->make(Requirements::class),
        static function (string $root): Container {
            /** @var Closure(string): Container $rebuild */
            $rebuild = require $root . '/bootstrap/app.php';

            return $rebuild($root);
        },
    ));
    $container->singleton('installer.kernel', static fn(Container $c): Kernel => new Kernel(
        [
            new RequestIdMiddleware($c->make(LogContext::class)),
            new SecurityHeadersMiddleware($c->make(Config::class)->bool('security.hsts.enabled'), $c->make(Config::class)->int('security.hsts.max_age')),
            new ErrorHandlerMiddleware($c->make(ErrorResponder::class), $c->make(LoggerInterface::class)),
        ],
        new InstallerHandler(
            $c->make(Installer::class),
            $c->make(InstallState::class),
            $c->make(Requirements::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(LoggerInterface::class),
        ),
    ));

    // --- Database --------------------------------------------------------
    $container->singleton(DatabaseConfig::class, static function (Container $c): DatabaseConfig {
        $db = $c->make(Config::class);

        return new DatabaseConfig(
            $db->string('database.host'),
            $db->int('database.port'),
            $db->string('database.database'),
            $db->string('database.username'),
            $db->string('database.password'),
        );
    });
    $container->singleton(Connection::class, static fn(Container $c): Connection => new Connection($c->make(DatabaseConfig::class)));

    // --- Security, sessions, audit ---------------------------------------
    $container->singleton(PasswordHasher::class, static fn(): PasswordHasher => new PasswordHasher());
    $container->singleton(SessionConfig::class, static function (Container $c): SessionConfig {
        $cfg = $c->make(Config::class);

        return new SessionConfig(
            $cfg->string('session.cookie'),
            $cfg->bool('session.secure'),
            $cfg->int('session.idle_timeout'),
            $cfg->int('session.absolute_lifetime'),
        );
    });
    $container->singleton(SessionStore::class, static fn(Container $c): SessionStore => new PdoSessionStore(
        $c->make(Connection::class),
        $c->make(SessionConfig::class),
        $c->make(Clock::class),
    ));
    $container->singleton(RateLimiter::class, static fn(Container $c): RateLimiter => new PdoRateLimiter($c->make(Connection::class)));
    $container->singleton(AuditLogger::class, static fn(Container $c): AuditLogger => new PdoAuditLogger(
        $c->make(Connection::class),
        $c->make(Redactor::class),
        $c->make(Clock::class),
        $c->make(LoggerInterface::class),
    ));

    // --- Encryption (ADR-014) ---------------------------------------------
    $container->singleton(KeyRingLoader::class, static fn(Container $c): KeyRingLoader => new KeyRingLoader(
        $c->make(Environment::class),
        $c->make(Paths::class)->storage('keys/keyring.php'),
    ));
    $container->singleton(KeyRing::class, static fn(Container $c): KeyRing => $c->make(KeyRingLoader::class)->load());
    $container->singleton(Encrypter::class, static fn(Container $c): Encrypter => new Encrypter($c->make(KeyRing::class)));
    // Every encrypted column must be listed here so key rotation can re-encrypt it.
    $container->singleton(SecretRotator::class, static fn(Container $c): SecretRotator => new SecretRotator(
        $c->make(Connection::class),
        $c->make(Encrypter::class),
        $c->make(KeyRing::class),
        [
            new EncryptedColumn('user_mfa_methods', 'id', 'secret', ['user_id'], static fn(array $row): string => MfaService::context((int) $row['user_id'])),
            new EncryptedColumn('oauth_clients', 'provider', 'client_secret', ['provider'], static fn(array $row): string => PdoOAuthClientRepository::context((string) $row['provider'])),
            new EncryptedColumn('connection_tokens', 'connection_id', 'refresh_token', ['connection_id'], static fn(array $row): string => GoogleAccessTokens::refreshContext((int) $row['connection_id'])),
            new EncryptedColumn('connection_tokens', 'connection_id', 'access_token', ['connection_id'], static fn(array $row): string => GoogleAccessTokens::accessContext((int) $row['connection_id'])),
            new EncryptedColumn('dataforseo_profiles', 'workspace_id', 'password', ['workspace_id'], static fn(array $row): string => PdoDataForSeoProfileRepository::context((int) $row['workspace_id'])),
            new EncryptedColumn('mail_settings', 'id', 'password', [], static fn(array $row): string => PdoMailSettingsRepository::CONTEXT),
            new EncryptedColumn('bing_profiles', 'workspace_id', 'api_key', ['workspace_id'], static fn(array $row): string => PdoBingAccountRepository::context((int) $row['workspace_id'])),
            new EncryptedColumn('crux_settings', 'workspace_id', 'api_key', ['workspace_id'], static fn(array $row): string => \SeoControlCenter\Modules\WebVitals\Persistence\PdoWebVitalsRepository::context((int) $row['workspace_id'])),
            new EncryptedColumn('ga4_settings', 'workspace_id', 'credentials', ['workspace_id'], static fn(array $row): string => \SeoControlCenter\Modules\Analytics\Persistence\PdoGa4Repository::context((int) $row['workspace_id'])),
            new EncryptedColumn('project_alert_webhooks', 'project_id', 'destination', ['public_id'], static fn(array $row): string => \SeoControlCenter\Modules\Alerts\Persistence\PdoWebhookRepository::context((string) $row['public_id'], 'destination')),
            new EncryptedColumn('project_alert_webhooks', 'project_id', 'bearer_token', ['public_id'], static fn(array $row): string => \SeoControlCenter\Modules\Alerts\Persistence\PdoWebhookRepository::context((string) $row['public_id'], 'bearer_token')),
            new EncryptedColumn('assistant_settings', 'workspace_id', 'api_key', ['workspace_id'], static fn(array $row): string => PdoAssistantSettingsRepository::context((int) $row['workspace_id'])),
        ],
    ));

    // --- Outbound HTTP (provider adapters) ---------------------------------
    // One short-lived DNS cache per process, shared by the crawler's address checks and the client.
    $container->singleton(HostResolver::class, static fn(): HostResolver => new CachingHostResolver(new SystemHostResolver()));
    $container->singleton(HttpClient::class, static fn(Container $c): HttpClient => new CurlHttpClient(30, 'SEO-Control-Center/' . $c->get('app.version'), $c->make(HostResolver::class)));

    // --- Queue, scheduler, cron (QUEUE_AND_CRON.md) -----------------------
    $container->singleton(SystemState::class, static fn(Container $c): SystemState => new SystemState($c->make(Connection::class), $c->make(Clock::class)));
    $container->singleton(JobRegistry::class, static function (Container $c): JobRegistry {
        $registry = new JobRegistry();
        foreach ($c->get('modules') as $module) {
            if ($module instanceof ProvidesJobs) {
                $module->registerJobs($registry, $c);
            }
        }

        return $registry;
    });
    $container->singleton(Queue::class, static fn(Container $c): Queue => new Queue($c->make(Connection::class), $c->make(JobRegistry::class), $c->make(Clock::class)));
    $container->singleton(Scheduler::class, static function (Container $c): Scheduler {
        $scheduler = new Scheduler($c->make(Connection::class), $c->make(Queue::class), $c->make(Clock::class));
        foreach ($c->get('modules') as $module) {
            if ($module instanceof ProvidesJobs) {
                $module->registerSchedule($scheduler);
            }
        }

        return $scheduler;
    });
    $container->singleton(Worker::class, static fn(Container $c): Worker => new Worker(
        $c->make(Connection::class),
        $c->make(JobRegistry::class),
        $c->make(Clock::class),
        $c->make(LoggerInterface::class),
        $c->make(LogContext::class),
        $c->make(Redactor::class),
        $c->make(Config::class)->int('queue.lease_seconds'),
        $c->make(FeatureManager::class),
    ));
    $container->singleton(CronRunner::class, static function (Container $c): CronRunner {
        $cfg = $c->make(Config::class);
        $memoryMb = $cfg->int('queue.memory_limit_mb');
        if ($memoryMb <= 0) {
            $limit = (string) ini_get('memory_limit');
            $bytes = match (strtolower(substr($limit, -1))) {
                'g' => (int) $limit * 1024 ** 3,
                'm' => (int) $limit * 1024 ** 2,
                'k' => (int) $limit * 1024,
                default => (int) $limit,
            };
            $memory = $bytes > 0 ? (int) ($bytes * 0.8) : null;
        } else {
            $memory = $memoryMb * 1024 * 1024;
        }

        return new CronRunner(
            $c->make(Connection::class),
            $c->make(Scheduler::class),
            $c->make(Worker::class),
            $c->make(SystemState::class),
            $c->make(LoggerInterface::class),
            $cfg->int('queue.cron_max_seconds'),
            $cfg->int('queue.worker_slots'),
            $memory,
            max(1, $cfg->int('queue.cron_interval_minutes', 1)),
        );
    });

    // --- Features (ADR-052) ------------------------------------------------
    $container->singleton(DisabledFeatures::class, static function (Container $c): DisabledFeatures {
        /** @var list<string> $disabled */
        $disabled = $c->make(Config::class)->array('features.disabled');

        return new ConfiguredDisabledFeatures($disabled);
    });
    $container->singleton(FeatureManager::class, static fn(Container $c): FeatureManager => new FeatureManager($c->make(DisabledFeatures::class)));

    // --- Modules ---------------------------------------------------------
    $modules = [];
    foreach ($config->array('modules') as $moduleClass) {
        if (!is_string($moduleClass) || !class_exists($moduleClass) || !is_subclass_of($moduleClass, Module::class)) {
            throw new ConfigException(sprintf('Invalid module in config/modules.php: %s', is_string($moduleClass) ? $moduleClass : get_debug_type($moduleClass)));
        }
        $module = new $moduleClass();
        $module->registerServices($container);
        $modules[] = $module;
    }
    $container->instance('modules', $modules);

    $routes = $container->make(RouteCollection::class);
    foreach ($modules as $module) {
        $module->registerRoutes($routes);
    }
    (static function (string $__file, RouteCollection $routes): void {
        require $__file;
    })($paths->routes() . '/web.php', $routes);

    $migrationPaths = [$paths->migrations()];
    foreach ($modules as $module) {
        array_push($migrationPaths, ...$module->migrationPaths());
    }
    $container->singleton(Migrator::class, static fn(Container $c): Migrator => new Migrator(
        $c->make(Connection::class),
        $migrationPaths,
        $c->make(LoggerInterface::class),
    ));

    $container->singleton(DatabaseBackup::class, static fn(Container $c): DatabaseBackup => new DatabaseBackup(
        $c->make(Connection::class),
        $c->make(Clock::class),
        $c->make(Paths::class)->storage('backups'),
        $version,
        max(1, $c->make(Config::class)->int('backup.keep')),
    ));

    // --- Console ---------------------------------------------------------
    $container->singleton(ConsoleApplication::class, static function (Container $c) use ($version): ConsoleApplication {
        $console = new ConsoleApplication($c->make(LoggerInterface::class), $version);
        $console->add(new MigrateCommand($c->make(Migrator::class), $c->make(Connection::class)));
        $console->add(new MigrateStatusCommand($c->make(Migrator::class)));
        $console->add(new MakeMigrationCommand($c->make(Paths::class)->migrations(), $c->make(Clock::class)));
        $console->add(new DbCheckCommand($c->make(Connection::class)));
        $console->add(new UserCreateCommand($c->make(UserProvisioning::class)));
        $console->add(new CryptoKeyGenerateCommand($c->make(KeyRingLoader::class)));
        $console->add(new CryptoRotateCommand($c->make(SecretRotator::class), $c->make(KeyRing::class)));
        $console->add(new CronRunCommand($c->make(CronRunner::class)));
        $console->add(new QueueStatusCommand($c->make(Queue::class), $c->make(Connection::class)));
        foreach (['keygen', 'build'] as $action) {
            $console->add(new ReleaseCommand($c->make(Paths::class)->base, $action));
        }
        foreach (['create', 'list', 'restore'] as $action) {
            $console->add(new BackupCommand($c->make(DatabaseBackup::class), $action));
        }

        return $console;
    });

    return $container;
};
