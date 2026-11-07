<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Keywords;

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
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Keywords\KeywordMetricsProvider;
use SeoControlCenter\Domain\Keywords\KeywordMetricsScheduler;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Modules\Keywords\Controllers\KeywordsController;
use SeoControlCenter\Modules\Keywords\Jobs\FetchKeywordMetricsJob;
use SeoControlCenter\Modules\Keywords\Persistence\PdoKeywordRepository;

/**
 * Keywords (roadmap 0.3): keywords per project and locale with search
 * volume, CPC and competition from the workspace's keyword metrics provider.
 */
final class KeywordsModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'keywords';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(KeywordRepository::class, static fn(Container $c): KeywordRepository => new PdoKeywordRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(KeywordMetricsScheduler::class, static fn(Container $c): KeywordMetricsScheduler => new QueuedKeywordMetricsScheduler(
            $c->make(Queue::class),
            $c->make(Clock::class),
        ));
        $container->singleton(KeywordMetricsUpdater::class, static fn(Container $c): KeywordMetricsUpdater => new KeywordMetricsUpdater(
            $c->make(KeywordRepository::class),
            $c->make(KeywordMetricsProvider::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
        ));
        $container->singleton(KeywordService::class, static fn(Container $c): KeywordService => new KeywordService(
            $c->make(KeywordRepository::class),
            $c->make(KeywordMetricsProvider::class),
            $c->make(KeywordMetricsScheduler::class),
            $c->make(ProjectService::class),
            $c->make(AuditLogger::class),
            $c->make(SearchPerformanceReader::class),
            $c->make(FeatureManager::class),
        ));
        $container->singleton(KeywordsController::class, static fn(Container $c): KeywordsController => new KeywordsController(
            $c->make(ProjectService::class),
            $c->make(KeywordService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(Clock::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $project = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/keywords';

        $routes->get($project, KeywordsController::class, 'index', 'projects.keywords');
        $routes->post($project, KeywordsController::class, 'add');
        $routes->post($project . '/refresh', KeywordsController::class, 'refresh');
        $routes->get($project . '/export', KeywordsController::class, 'export', 'projects.keywords.export');
        $routes->post($project . '/{keyword:' . Uuid::ROUTE_PATTERN . '}/delete', KeywordsController::class, 'remove');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(FetchKeywordMetricsJob::TYPE, static fn(): FetchKeywordMetricsJob => new FetchKeywordMetricsJob(
            $container->make(KeywordMetricsUpdater::class),
            $container->make(Connection::class),
            $container->make(Queue::class),
        ));
        $jobs->requireFeature(FetchKeywordMetricsJob::TYPE, Feature::KeywordMetrics);
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // No scheduled refresh: fetching metrics costs money, so it only runs when a user asks.
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
