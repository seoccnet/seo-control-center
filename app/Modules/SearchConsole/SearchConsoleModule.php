<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Config\Config;
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
use SeoControlCenter\Domain\Integrations\ConnectionRepository;
use SeoControlCenter\Domain\Integrations\OAuthClientRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\ImportScheduler;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;
use SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceProvider;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Modules\SearchConsole\Controllers\GoogleSettingsController;
use SeoControlCenter\Modules\SearchConsole\Controllers\IntegrationsController;
use SeoControlCenter\Modules\SearchConsole\Controllers\PerformanceController;
use SeoControlCenter\Modules\SearchConsole\Controllers\ProjectSearchConsoleController;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleAccessTokens;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleOAuth;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleSearchConsoleApi;
use SeoControlCenter\Modules\SearchConsole\Import\QueuedImportScheduler;
use SeoControlCenter\Modules\SearchConsole\Import\SearchConsoleImporter;
use SeoControlCenter\Modules\SearchConsole\Jobs\ImportSearchConsoleJob;
use SeoControlCenter\Modules\SearchConsole\Jobs\SyncSearchConsoleJob;
use SeoControlCenter\Modules\SearchConsole\Persistence\PdoConnectionRepository;
use SeoControlCenter\Modules\SearchConsole\Persistence\PdoOAuthClientRepository;
use SeoControlCenter\Modules\SearchConsole\Persistence\PdoProjectPropertyRepository;
use SeoControlCenter\Modules\SearchConsole\Persistence\PdoSearchConsoleExtras;
use SeoControlCenter\Modules\SearchConsole\Persistence\PdoSearchPerformanceReader;
use SeoControlCenter\Modules\SearchConsole\Persistence\PdoSearchPerformanceStore;

/**
 * Google Search Console (roadmap 0.2): connection, property discovery,
 * project linking, background imports and the performance dashboard.
 */
final class SearchConsoleModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'search_console';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(OAuthClientRepository::class, static fn(Container $c): OAuthClientRepository => new PdoOAuthClientRepository(
            $c->make(Connection::class),
            $c->make(Encrypter::class),
            $c->make(Clock::class),
        ));
        $container->singleton(ConnectionRepository::class, static fn(Container $c): ConnectionRepository => new PdoConnectionRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(ProjectPropertyRepository::class, static fn(Container $c): ProjectPropertyRepository => new PdoProjectPropertyRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(GoogleOAuth::class, static fn(Container $c): GoogleOAuth => new GoogleOAuth($c->make(HttpClient::class)));
        $container->singleton(GoogleAccessTokens::class, static fn(Container $c): GoogleAccessTokens => new GoogleAccessTokens(
            $c->make(ConnectionRepository::class),
            $c->make(OAuthClientRepository::class),
            $c->make(GoogleOAuth::class),
            $c->make(Encrypter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
        ));
        $container->singleton(SearchPerformanceProvider::class, static fn(Container $c): SearchPerformanceProvider => new GoogleSearchConsoleApi(
            $c->make(HttpClient::class),
            $c->make(GoogleAccessTokens::class),
        ));
        $container->singleton(SearchConsoleService::class, static fn(Container $c): SearchConsoleService => new SearchConsoleService(
            $c->make(OAuthClientRepository::class),
            $c->make(ConnectionRepository::class),
            $c->make(ProjectPropertyRepository::class),
            $c->make(GoogleOAuth::class),
            $c->make(GoogleAccessTokens::class),
            $c->make(SearchPerformanceProvider::class),
            $c->make(Authorizer::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(Config::class)->string('app.url'),
            $c->make(ImportScheduler::class),
        ));
        $container->singleton(SearchPerformanceStore::class, static fn(Container $c): SearchPerformanceStore => new PdoSearchPerformanceStore(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(SearchPerformanceReader::class, static fn(Container $c): SearchPerformanceReader => new PdoSearchPerformanceReader(
            $c->make(Connection::class),
        ));
        $container->singleton(SearchConsoleExtras::class, static fn(Container $c): SearchConsoleExtras => new PdoSearchConsoleExtras(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(ImportScheduler::class, static fn(Container $c): ImportScheduler => new QueuedImportScheduler(
            $c->make(Queue::class),
            $c->make(Clock::class),
        ));
        $container->singleton(SearchConsoleImporter::class, static fn(Container $c): SearchConsoleImporter => new SearchConsoleImporter(
            $c->make(ProjectPropertyRepository::class),
            $c->make(ConnectionRepository::class),
            $c->make(SearchPerformanceProvider::class),
            $c->make(SearchPerformanceStore::class),
            $c->make(SearchConsoleExtras::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
            max(1, $c->make(Config::class)->int('search_console.backfill_days')),
            max(1, min(100000, $c->make(Config::class)->int('search_console.max_rows_per_day'))),
            max(1, $c->make(Config::class)->int('search_console.totals_refresh_hours')),
        ));
        $container->singleton(PerformanceController::class, static fn(Container $c): PerformanceController => new PerformanceController(
            $c->make(ProjectService::class),
            $c->make(SearchConsoleService::class),
            $c->make(SearchPerformanceReader::class),
            $c->make(SearchPerformanceStore::class),
            $c->make(ImportScheduler::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(FeatureManager::class),
            $c->make(\SeoControlCenter\Modules\Annotations\AnnotationService::class),
            $c->make(SearchConsoleExtras::class),
            $c->make(Clock::class),
        ));
        $container->singleton(IntegrationsController::class, static fn(Container $c): IntegrationsController => new IntegrationsController(
            $c->make(SearchConsoleService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(LoggerInterface::class),
        ));
        $container->singleton(GoogleSettingsController::class, static fn(Container $c): GoogleSettingsController => new GoogleSettingsController(
            $c->make(SearchConsoleService::class),
            $c->make(Authorizer::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(ProjectSearchConsoleController::class, static fn(Container $c): ProjectSearchConsoleController => new ProjectSearchConsoleController(
            $c->make(SearchConsoleService::class),
            $c->make(ProjectService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $connection = '{connection:' . Uuid::ROUTE_PATTERN . '}';
        $project = '{project:' . Uuid::ROUTE_PATTERN . '}';

        $routes->get('/integrations', IntegrationsController::class, 'index', 'integrations');
        $routes->post('/integrations/google/connect', IntegrationsController::class, 'connect');
        $routes->get('/integrations/google/callback', IntegrationsController::class, 'callback', 'integrations.google.callback');
        $routes->post('/integrations/' . $connection . '/refresh', IntegrationsController::class, 'refresh');
        $routes->post('/integrations/' . $connection . '/disconnect', IntegrationsController::class, 'disconnect');

        $routes->get('/system/integrations/google', GoogleSettingsController::class, 'show', 'system.integrations.google');
        $routes->post('/system/integrations/google', GoogleSettingsController::class, 'save');

        $routes->get('/projects/' . $project . '/search-console', ProjectSearchConsoleController::class, 'show', 'projects.search_console');
        $routes->post('/projects/' . $project . '/search-console', ProjectSearchConsoleController::class, 'link');
        $routes->post('/projects/' . $project . '/search-console/unlink', ProjectSearchConsoleController::class, 'unlink');

        $routes->get('/projects/' . $project . '/performance', PerformanceController::class, 'show', 'projects.performance');
        $routes->post('/projects/' . $project . '/performance/import', PerformanceController::class, 'import');
        $routes->get('/projects/' . $project . '/performance/export', PerformanceController::class, 'export', 'projects.performance.export');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(ImportSearchConsoleJob::TYPE, static fn(): ImportSearchConsoleJob => new ImportSearchConsoleJob(
            $container->make(SearchConsoleImporter::class),
            $container->make(Connection::class),
            $container->make(Queue::class),
        ));
        $jobs->register(SyncSearchConsoleJob::TYPE, static fn(): SyncSearchConsoleJob => new SyncSearchConsoleJob(
            $container->make(ProjectPropertyRepository::class),
            $container->make(ImportScheduler::class),
        ));
        $jobs->requireFeature(ImportSearchConsoleJob::TYPE, Feature::SearchConsole);
        $jobs->requireFeature(SyncSearchConsoleJob::TYPE, Feature::SearchConsole);
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // Google finalizes a day about 2-3 days later; checking every 6 hours picks new days up promptly.
        $scheduler->add(new ScheduledTask('search_console.sync', '23 */6 * * *', SyncSearchConsoleJob::TYPE, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
