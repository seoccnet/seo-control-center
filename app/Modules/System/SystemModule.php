<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Backup\DatabaseBackup;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Crypto\KeyRing;
use SeoControlCenter\Core\Crypto\KeyRingLoader;
use SeoControlCenter\Core\Crypto\SecretRotator;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\PdoQueueMonitor;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Queue\QueueMonitor;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Session\SessionConfig;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Core\Support\SystemState;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\Update\MaintenanceMode;
use SeoControlCenter\Core\Update\UpdateInstaller;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Modules\System\Controllers\HealthController;
use SeoControlCenter\Modules\System\Controllers\JobsController;
use SeoControlCenter\Modules\System\Controllers\MaintenanceController;
use SeoControlCenter\Modules\System\Controllers\SystemHealthController;
use SeoControlCenter\Modules\System\Controllers\UpdatesController;
use SeoControlCenter\Modules\System\Health\SystemHealth;
use SeoControlCenter\Modules\System\Jobs\ReencryptSecretsJob;
use SeoControlCenter\Modules\System\Jobs\SystemCleanupJob;
use SeoControlCenter\Modules\System\Support\SupportBundle;

/**
 * Installation-level concerns: liveness probe, System Health, housekeeping
 * jobs and key rotation.
 */
final class SystemModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'system';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(HealthController::class, static fn(Container $c): HealthController => new HealthController(
            $c->make(ResponseFactory::class),
        ));
        $container->singleton(SystemHealth::class, static fn(Container $c): SystemHealth => new SystemHealth(
            $c->make(Connection::class),
            $c->make(Paths::class),
            $c->make(Queue::class),
            $c->make(SystemState::class),
            $c->make(KeyRing::class),
            $c->make(KeyRingLoader::class),
            $c->make(SecretRotator::class),
            $c->make(Clock::class),
            (string) $c->get('app.version'),
            $c->make(Config::class)->string('app.url'),
            $c->make(DatabaseBackup::class),
            max(1, $c->make(Config::class)->int('queue.cron_interval_minutes', 1)),
        ));
        $container->singleton(SupportBundle::class, static fn(Container $c): SupportBundle => new SupportBundle(
            $c->make(SystemHealth::class),
            $c->make(Migrator::class),
            $c->make(Queue::class),
            $c->make(Connection::class),
            $c->make(Config::class),
            $c->make(Paths::class),
            $c->make(Clock::class),
            (string) $c->get('app.version'),
        ));
        $container->singleton(MaintenanceController::class, static fn(Container $c): MaintenanceController => new MaintenanceController(
            $c->make(DatabaseBackup::class),
            $c->make(SupportBundle::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(QueueMonitor::class, static fn(Container $c): QueueMonitor => new PdoQueueMonitor($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(JobsController::class, static fn(Container $c): JobsController => new JobsController(
            $c->make(QueueMonitor::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(UpdateInstaller::class, static fn(Container $c): UpdateInstaller => new UpdateInstaller(
            $c->make(Paths::class)->base,
            $c->make(DatabaseBackup::class),
            new MaintenanceMode($c->make(Paths::class)->base),
            static fn(): array => $c->make(Migrator::class)->migrate(),
        ));
        $container->singleton(UpdatesController::class, static fn(Container $c): UpdatesController => new UpdatesController(
            $c->make(UpdateInstaller::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
            $c->make(Connection::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(Paths::class)->base,
            (string) $c->get('app.version'),
            $c->make(Config::class)->string('update.public_key'),
            max(1, $c->make(Config::class)->int('update.max_upload_mb', 64)),
        ));
        $container->singleton(SystemHealthController::class, static fn(Container $c): SystemHealthController => new SystemHealthController(
            $c->make(SystemHealth::class),
            $c->make(Migrator::class),
            $c->make(AuditLogger::class),
            $c->make(Authorizer::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/health/live', HealthController::class, 'live', 'health.live', public: true);
        $routes->get('/system/health', SystemHealthController::class, 'index', 'system.health');
        $routes->post('/system/migrations', SystemHealthController::class, 'applyMigrations');
        $routes->get('/system/backups', MaintenanceController::class, 'backups', 'system.backups');
        $routes->post('/system/backups', MaintenanceController::class, 'createBackup');
        $routes->get('/system/backups/{name:scc-backup-\d{8}-\d{6}\.sql\.gz}', MaintenanceController::class, 'downloadBackup');
        $job = '/system/jobs/{job:' . Uuid::ROUTE_PATTERN . '}';
        $routes->get('/system/jobs', JobsController::class, 'index', 'system.jobs');
        $routes->post('/system/jobs/retry-all', JobsController::class, 'retryAll');
        $routes->post($job . '/retry', JobsController::class, 'retry');
        $routes->post($job . '/dismiss', JobsController::class, 'dismiss');
        $routes->get('/system/updates', UpdatesController::class, 'index', 'system.updates');
        $routes->post('/system/updates/upload', UpdatesController::class, 'upload');
        $routes->post('/system/updates/install', UpdatesController::class, 'install');
        $routes->post('/system/updates/discard', UpdatesController::class, 'discard');
        $routes->get('/system/support-bundle', MaintenanceController::class, 'supportBundle', 'system.support_bundle');
    }

    public function migrationPaths(): array
    {
        return [];
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(SystemCleanupJob::TYPE, static fn(): SystemCleanupJob => new SystemCleanupJob(
            $container->make(Connection::class),
            $container->make(SessionConfig::class),
            $container->make(Clock::class),
            $container->make(LoggerInterface::class),
        ));
        $jobs->register(ReencryptSecretsJob::TYPE, static fn(): ReencryptSecretsJob => new ReencryptSecretsJob(
            $container->make(SecretRotator::class),
            $container->make(Queue::class),
        ));
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask('system.cleanup', '7 * * * *', SystemCleanupJob::TYPE, 'cleanup'));
    }
}
