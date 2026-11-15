<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteMigration;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;
use SeoControlCenter\Modules\SiteMigration\Controllers\SiteMigrationController;
use SeoControlCenter\Modules\SiteMigration\Jobs\RunSiteMigrationJob;
use SeoControlCenter\Modules\SiteMigration\Jobs\SiteMigrationJobs;
use SeoControlCenter\Modules\SiteMigration\Persistence\PdoSiteMigrationRepository;

/** Site migration assistant (1.2.0): redirect mapping, redirect tests and post-launch results. */
final class SiteMigrationModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'site-migration';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(SiteMigrationRepository::class, static fn(Container $c): SiteMigrationRepository => new PdoSiteMigrationRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(SiteMigrationScheduler::class, static fn(Container $c): SiteMigrationScheduler => new SiteMigrationJobs($c->make(Queue::class)));
        $container->singleton(UrlProbe::class, static fn(Container $c): UrlProbe => new CrawlerUrlProbe($c->make(SiteCrawler::class)));
        $container->singleton(SiteMigrationService::class, static fn(Container $c): SiteMigrationService => new SiteMigrationService(
            $c->make(SiteMigrationRepository::class),
            $c->make(SiteMigrationScheduler::class),
            $c->make(UrlProbe::class),
            $c->make(SearchPerformanceReader::class),
            $c->make(SiteAuditRepository::class),
            $c->make(Authorizer::class),
            $c->make(FeatureManager::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
        ));
        $container->singleton(SiteMigrationController::class, static fn(Container $c): SiteMigrationController => new SiteMigrationController($c->make(ProjectService::class), $c->make(SiteMigrationService::class), $c->make(ResponseFactory::class), $c->make(ViewRenderer::class)));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/site-migration';
        $plan = $base . '/{plan:' . Uuid::ROUTE_PATTERN . '}';
        $routes->get($base, SiteMigrationController::class, 'index', 'site_migration.index');
        $routes->post($base, SiteMigrationController::class, 'create', 'site_migration.create');
        $routes->get($plan, SiteMigrationController::class, 'show', 'site_migration.show');
        $routes->post($plan, SiteMigrationController::class, 'update', 'site_migration.update');
        $routes->post($plan . '/delete', SiteMigrationController::class, 'delete', 'site_migration.delete');
        $routes->post($plan . '/old', SiteMigrationController::class, 'importOld', 'site_migration.old');
        $routes->post($plan . '/new', SiteMigrationController::class, 'importNew', 'site_migration.new');
        $routes->post($plan . '/suggest', SiteMigrationController::class, 'suggest', 'site_migration.suggest');
        $routes->post($plan . '/test', SiteMigrationController::class, 'test', 'site_migration.test');
        $routes->post($plan . '/target', SiteMigrationController::class, 'target', 'site_migration.target');
        $routes->get($plan . '/export', SiteMigrationController::class, 'export', 'site_migration.export');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(SiteMigrationJobs::RUN, static fn(): RunSiteMigrationJob => new RunSiteMigrationJob($container->make(SiteMigrationService::class)));
    }

    public function registerSchedule(Scheduler $scheduler): void {}

    public function migrationPaths(): array
    {
        return [];
    }
}
