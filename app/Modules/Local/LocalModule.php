<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Local\LocalSearchProvider;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Local\Controllers\LocalController;
use SeoControlCenter\Modules\Local\Jobs\CheckLocalJob;
use SeoControlCenter\Modules\Local\Jobs\GridLocalJob;
use SeoControlCenter\Modules\Local\Jobs\LocalJobs;
use SeoControlCenter\Modules\Local\Jobs\ScheduleLocalChecksJob;
use SeoControlCenter\Modules\Local\Persistence\PdoLocalRepository;

/**
 * Local SEO (roadmap 1.3): Google Maps positions for local keywords at a
 * point on the map, the businesses around them and the Business Profile.
 */
final class LocalModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'local';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(LocationRepository::class, static fn(Container $c): LocationRepository => new Persistence\PdoLocationRepository($c->make(Connection::class)));
        $container->singleton(\SeoControlCenter\Domain\Local\LocalReviewsProvider::class, static fn(Container $c): \SeoControlCenter\Domain\Local\LocalReviewsProvider => new \SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoReviews($c->make(\SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient::class)));
        $container->singleton(LocationScheduler::class, static fn(Container $c): LocationScheduler => new Jobs\LocationJobs($c->make(Queue::class), $c->make(Clock::class)));
        $container->singleton(LocationService::class, static fn(Container $c): LocationService => new LocationService($c->make(LocationRepository::class), $c->make(LocalRepository::class), $c->make(LocationScheduler::class), $c->make(LocalSearchProvider::class), $c->make(\SeoControlCenter\Domain\Local\LocalReviewsProvider::class), $c->make(ProjectService::class), $c->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class), $c->make(FeatureManager::class), $c->make(RateLimiter::class), $c->make(AuditLogger::class), $c->make(Clock::class)));
        $container->singleton(Controllers\LocationsController::class, static fn(Container $c): Controllers\LocationsController => new Controllers\LocationsController($c->make(LocationService::class), $c->make(ProjectService::class), $c->make(ResponseFactory::class), $c->make(ViewRenderer::class)));
        $container->singleton(LocalRepository::class, static fn(Container $c): LocalRepository => new PdoLocalRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(LocalScheduler::class, static fn(Container $c): LocalScheduler => new LocalJobs($c->make(Queue::class)));
        $container->singleton(LocalService::class, static fn(Container $c): LocalService => new LocalService(
            $c->make(LocalRepository::class),
            $c->make(LocalScheduler::class),
            $c->make(LocalSearchProvider::class),
            $c->make(ProjectService::class),
            $c->make(FeatureManager::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
            $c->make(LocationRepository::class),
            $c->has(\SeoControlCenter\Modules\SiteAudit\SiteAuditRepository::class) ? $c->make(\SeoControlCenter\Modules\SiteAudit\SiteAuditRepository::class) : null,
        ));
        $container->singleton(LocalController::class, static fn(Container $c): LocalController => new LocalController(
            $c->make(LocalService::class),
            $c->make(ProjectService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/local';
        $routes->get($base, LocalController::class, 'show', 'projects.local');
        $routes->post($base . '/settings', LocalController::class, 'settings');
        $routes->post($base . '/keywords', LocalController::class, 'addKeywords');
        $routes->post($base . '/keywords/delete', LocalController::class, 'removeKeyword');
        $routes->post($base . '/check', LocalController::class, 'check');
        $routes->post($base . '/grid', LocalController::class, 'grid');
        $routes->get($base . '/locations', Controllers\LocationsController::class, 'show');
        $routes->post($base . '/locations', Controllers\LocationsController::class, 'add');
        $locationBase = $base . '/locations/{location:primary|' . Uuid::ROUTE_PATTERN . '}';
        $routes->post($locationBase . '/delete', Controllers\LocationsController::class, 'remove');
        $routes->post($locationBase . '/settings', Controllers\LocationsController::class, 'update');
        $routes->post($locationBase . '/check', Controllers\LocationsController::class, 'check');
        $routes->post($locationBase . '/reviews', Controllers\LocationsController::class, 'reviews');
        $routes->post($locationBase . '/grid', Controllers\LocationsController::class, 'grid');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(Jobs\LocationJobs::RUN, static fn(): Jobs\LocationJob => new Jobs\LocationJob($container->make(LocationService::class), $container->make(Connection::class)));
        $jobs->register(Jobs\LocationJobs::SCHEDULE, static fn(): Jobs\ScheduleLocationsJob => new Jobs\ScheduleLocationsJob($container->make(LocationService::class)));
        $jobs->requireFeature(Jobs\LocationJobs::RUN, Feature::RankTracking);
        $jobs->requireFeature(Jobs\LocationJobs::SCHEDULE, Feature::RankTracking);
        $jobs->register(LocalJobs::CHECK, static fn(): CheckLocalJob => new CheckLocalJob(
            $container->make(LocalService::class),
            $container->make(Connection::class),
            $container->make(Queue::class),
        ));
        $jobs->register(LocalJobs::GRID, static fn(): GridLocalJob => new GridLocalJob(
            $container->make(LocalService::class),
            $container->make(Connection::class),
            $container->make(Queue::class),
        ));
        $jobs->register(LocalJobs::SCHEDULE, static fn(): ScheduleLocalChecksJob => new ScheduleLocalChecksJob($container->make(LocalService::class)));
        foreach ([LocalJobs::CHECK, LocalJobs::GRID, LocalJobs::SCHEDULE] as $type) {
            $jobs->requireFeature($type, Feature::RankTracking);
        }
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // Daily; projects with the weekly check on get one every 7 days.
        $scheduler->add(new ScheduledTask('local.schedule', '29 4 * * *', LocalJobs::SCHEDULE, 'default'));
        $scheduler->add(new ScheduledTask('local.locations.schedule', '39 4 * * *', Jobs\LocationJobs::SCHEDULE, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
