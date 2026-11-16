<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors;

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
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Modules\Competitors\Controllers\CompetitorsController;
use SeoControlCenter\Modules\Competitors\Persistence\PdoCompetitorRepository;
use SeoControlCenter\Modules\Rankings\SerpObservers;

/**
 * Competitors (roadmap 0.5): competing domains per project, measured on the
 * SERPs that rank checks fetch, with discovery, visibility and keyword gaps.
 */
final class CompetitorsModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'competitors';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(CompetitorRepository::class, static fn(Container $c): CompetitorRepository => new PdoCompetitorRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        // Rank checks hand every SERP to this observer (see RankingsModule).
        $container->singleton(CompetitorTracker::class, static fn(Container $c): CompetitorTracker => new CompetitorTracker(
            $c->make(CompetitorRepository::class),
        ));
        $container->make(SerpObservers::class)->add(static fn(): SerpObserver => $container->make(CompetitorTracker::class));
        $container->singleton(CompetitorService::class, static fn(Container $c): CompetitorService => new CompetitorService(
            $c->make(CompetitorRepository::class),
            $c->make(RankingRepository::class),
            $c->make(ProjectService::class),
            $c->make(AuditLogger::class),
            $c->make(FeatureManager::class),
        ));
        $container->singleton(CompetitorWatchRepository::class, static fn(Container $c): CompetitorWatchRepository => new Persistence\PdoCompetitorWatchRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(CompetitorWatchScheduler::class, static fn(Container $c): CompetitorWatchScheduler => new Jobs\CompetitorWatchJobs($c->make(Queue::class)));
        $container->singleton(CompetitorSitemapReader::class, static fn(Container $c): CompetitorSitemapReader => new CrawlerCompetitorSitemaps($c->make(\SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler::class)));
        $container->singleton(CompetitorWatchService::class, static fn(Container $c): CompetitorWatchService => new CompetitorWatchService(
            $c->make(CompetitorWatchRepository::class),
            $c->make(CompetitorRepository::class),
            $c->make(CompetitorSitemapReader::class),
            $c->make(CompetitorWatchScheduler::class),
            $c->make(\SeoControlCenter\Domain\Keywords\KeywordRepository::class),
            $c->make(ProjectService::class),
            $c->make(FeatureManager::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(\Psr\Log\LoggerInterface::class),
        ));
        $container->singleton(Controllers\CompetitorWatchController::class, static fn(Container $c): Controllers\CompetitorWatchController => new Controllers\CompetitorWatchController(
            $c->make(CompetitorWatchService::class),
            $c->make(ProjectService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(CompetitorsController::class, static fn(Container $c): CompetitorsController => new CompetitorsController(
            $c->make(ProjectService::class),
            $c->make(CompetitorService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $path = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/competitors';
        $competitor = '/{competitor:' . Uuid::ROUTE_PATTERN . '}';

        $routes->get($path, CompetitorsController::class, 'index', 'projects.competitors');
        $routes->post($path, CompetitorsController::class, 'add');
        $routes->get($path . '/new-pages', Controllers\CompetitorWatchController::class, 'show', 'projects.competitors.watch');
        $routes->post($path . '/new-pages/watch', Controllers\CompetitorWatchController::class, 'toggle', 'projects.competitors.watch.toggle');
        $routes->get($path . $competitor, CompetitorsController::class, 'show', 'projects.competitors.show');
        $routes->post($path . $competitor . '/delete', CompetitorsController::class, 'remove');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(Jobs\CompetitorWatchJobs::DISPATCH, static fn() => Jobs\CompetitorWatchJobs::handler($container->make(CompetitorWatchService::class), true));
        $jobs->register(Jobs\CompetitorWatchJobs::CHECK, static fn() => Jobs\CompetitorWatchJobs::handler($container->make(CompetitorWatchService::class), false));
        foreach ([Jobs\CompetitorWatchJobs::DISPATCH, Jobs\CompetitorWatchJobs::CHECK] as $type) {
            $jobs->requireFeature($type, Feature::Competitors);
        }
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask(Jobs\CompetitorWatchJobs::DISPATCH, '27 6 * * *', Jobs\CompetitorWatchJobs::DISPATCH, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
