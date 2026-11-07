<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Client\HttpClient;
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
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Bing\Api\BingWebmasterClient;
use SeoControlCenter\Modules\Bing\Controllers\BingController;
use SeoControlCenter\Modules\Bing\Controllers\BingPerformanceController;
use SeoControlCenter\Modules\Bing\Controllers\ProjectBingController;
use SeoControlCenter\Modules\Bing\Import\BingImporter;
use SeoControlCenter\Modules\Bing\Import\QueuedBingImportScheduler;
use SeoControlCenter\Modules\Bing\Jobs\ImportBingJob;
use SeoControlCenter\Modules\Bing\Jobs\SyncBingJob;
use SeoControlCenter\Modules\Bing\Persistence\PdoBingAccountRepository;
use SeoControlCenter\Modules\Bing\Persistence\PdoBingDataRepository;

/**
 * Bing Webmaster Tools (roadmap 1.1): a per-workspace API key, project
 * links to Bing sites, daily background imports and the Bing performance view.
 */
final class BingModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'bing';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(BingAccountRepository::class, static fn(Container $c): BingAccountRepository => new PdoBingAccountRepository(
            $c->make(Connection::class),
            $c->make(Encrypter::class),
            $c->make(Clock::class),
        ));
        $container->singleton(BingDataRepository::class, static fn(Container $c): BingDataRepository => new PdoBingDataRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(BingImportScheduler::class, static fn(Container $c): BingImportScheduler => new QueuedBingImportScheduler(
            $c->make(Queue::class),
            $c->make(Clock::class),
        ));
        $container->singleton(BingWebmasterClient::class, static fn(Container $c): BingWebmasterClient => new BingWebmasterClient($c->make(HttpClient::class)));
        $container->singleton(BingImporter::class, static fn(Container $c): BingImporter => new BingImporter(
            $c->make(BingDataRepository::class),
            $c->make(BingAccountRepository::class),
            $c->make(BingWebmasterClient::class),
            $c->make(LoggerInterface::class),
        ));
        $container->singleton(BingService::class, static fn(Container $c): BingService => new BingService(
            $c->make(BingAccountRepository::class),
            $c->make(BingDataRepository::class),
            $c->make(BingWebmasterClient::class),
            $c->make(BingImportScheduler::class),
            $c->make(Authorizer::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(FeatureManager::class),
            $c->make(Clock::class),
        ));
        $container->singleton(BingController::class, static fn(Container $c): BingController => new BingController(
            $c->make(BingService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(LoggerInterface::class),
        ));
        $container->singleton(ProjectBingController::class, static fn(Container $c): ProjectBingController => new ProjectBingController(
            $c->make(BingService::class),
            $c->make(ProjectService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(BingPerformanceController::class, static fn(Container $c): BingPerformanceController => new BingPerformanceController(
            $c->make(ProjectService::class),
            $c->make(BingService::class),
            $c->make(BingDataRepository::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(\SeoControlCenter\Modules\Annotations\AnnotationService::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $project = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}';

        $routes->get('/integrations/bing', BingController::class, 'show', 'integrations.bing');
        $routes->post('/integrations/bing', BingController::class, 'connect');
        $routes->post('/integrations/bing/refresh', BingController::class, 'refresh');
        $routes->post('/integrations/bing/disconnect', BingController::class, 'disconnect');

        $routes->get($project . '/bing', ProjectBingController::class, 'show', 'projects.bing');
        $routes->post($project . '/bing', ProjectBingController::class, 'link');
        $routes->post($project . '/bing/unlink', ProjectBingController::class, 'unlink');

        $routes->get($project . '/performance/bing', BingPerformanceController::class, 'show', 'projects.performance.bing');
        $routes->post($project . '/performance/bing/import', BingPerformanceController::class, 'import');
        $routes->get($project . '/performance/bing/export', BingPerformanceController::class, 'export', 'projects.performance.bing.export');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(ImportBingJob::TYPE, static fn(): ImportBingJob => new ImportBingJob(
            $container->make(BingImporter::class),
            $container->make(Connection::class),
        ));
        $jobs->register(SyncBingJob::TYPE, static fn(): SyncBingJob => new SyncBingJob(
            $container->make(BingDataRepository::class),
            $container->make(BingImportScheduler::class),
        ));
        $jobs->requireFeature(ImportBingJob::TYPE, Feature::BingWebmaster);
        $jobs->requireFeature(SyncBingJob::TYPE, Feature::BingWebmaster);
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // Bing updates its data once a day; twice a day picks it up without waiting long.
        $scheduler->add(new ScheduledTask('bing.sync', '47 5,17 * * *', SyncBingJob::TYPE, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
