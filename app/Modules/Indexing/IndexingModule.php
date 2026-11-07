<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing;

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;

final class IndexingModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'indexing';
    }
    public function registerServices(Container $container): void
    {
        $container->singleton(IndexingRepository::class, static fn(Container $c): IndexingRepository => new Persistence\PdoIndexingRepository($c->make(Connection::class)));
        $container->singleton(IndexingEvidence::class, static fn(Container $c): IndexingEvidence => new Persistence\PdoIndexingEvidence($c->make(Connection::class)));
        $container->singleton(InspectionProvider::class, static fn(Container $c): InspectionProvider => new GoogleInspectionProvider($c->make(\SeoControlCenter\Core\Http\Client\HttpClient::class), $c->make(\SeoControlCenter\Modules\SearchConsole\Google\GoogleAccessTokens::class)));
        $container->singleton(InspectionScheduler::class, static fn(Container $c): InspectionScheduler => new QueuedInspectionScheduler($c->make(\SeoControlCenter\Core\Queue\Queue::class), $c->make(Clock::class)));
        $container->singleton(IndexingService::class, static fn(Container $c): IndexingService => new IndexingService($c->make(IndexingRepository::class), $c->make(InspectionProvider::class), $c->make(InspectionScheduler::class), $c->make(\SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository::class), $c->make(\SeoControlCenter\Domain\Integrations\ConnectionRepository::class), $c->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class), $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class), $c->make(\SeoControlCenter\Core\RateLimit\RateLimiter::class), $c->make(\SeoControlCenter\Core\Audit\AuditLogger::class), $c->make(Clock::class), $c->make(\SeoControlCenter\Core\Features\FeatureManager::class), $c->make(\SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras::class), $c->make(IndexingEvidence::class)));
        $container->singleton(Controllers\IndexingController::class, static fn(Container $c): Controllers\IndexingController => new Controllers\IndexingController($c->make(\SeoControlCenter\Domain\Projects\ProjectService::class), $c->make(IndexingService::class), $c->make(\SeoControlCenter\Core\Http\ResponseFactory::class), $c->make(\SeoControlCenter\Core\View\ViewRenderer::class)));
    }
    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/indexing';
        $routes->get($base, Controllers\IndexingController::class, 'index', 'indexing.index');
        $routes->post($base, Controllers\IndexingController::class, 'request');
        $routes->get($base . '/history', Controllers\IndexingController::class, 'history', 'indexing.history');
        $routes->post($base . '/requests', Controllers\IndexingController::class, 'recordRequest', 'indexing.requests.record');
    }
    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register('indexing.inspect', static fn(): Jobs\InspectUrlJob => new Jobs\InspectUrlJob($container->make(IndexingService::class), $container->make(Connection::class)));
        $jobs->register('indexing.schedule', static fn(): Jobs\ScheduleInspectionsJob => new Jobs\ScheduleInspectionsJob($container->make(IndexingService::class)));
        foreach (['indexing.inspect', 'indexing.schedule'] as $type) {
            $jobs->requireFeature($type, \SeoControlCenter\Core\Features\Feature::SearchConsole);
        }
    }
    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask('indexing.schedule', '37 * * * *', 'indexing.schedule'));
    }
    public function migrationPaths(): array
    {
        return [];
    }
}
