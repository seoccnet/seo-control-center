<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings;

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
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankCheckScheduler;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Domain\Rankings\SerpProvider;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Modules\Rankings\Controllers\RankingsController;
use SeoControlCenter\Modules\Rankings\Jobs\CheckRankingsJob;
use SeoControlCenter\Modules\Rankings\Jobs\ScheduleRankChecksJob;
use SeoControlCenter\Modules\Rankings\Persistence\PdoRankingRepository;

/**
 * Rank tracking (roadmap 0.4): opt-in daily or weekly checks of a
 * project's keywords, snapshots, visibility and winners/losers.
 */
final class RankingsModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'rankings';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(RankingRepository::class, static fn(Container $c): RankingRepository => new PdoRankingRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(RankCheckScheduler::class, static fn(Container $c): RankCheckScheduler => new QueuedRankCheckScheduler(
            $c->make(Queue::class),
            $c->make(Clock::class),
        ));
        $container->singleton(SerpObservers::class, static fn(): SerpObservers => new SerpObservers());
        $container->singleton(SerpObserver::class, static fn(Container $c): SerpObserver => $c->make(SerpObservers::class));
        $container->singleton(RankChecker::class, static fn(Container $c): RankChecker => new RankChecker(
            $c->make(RankingRepository::class),
            $c->make(SerpProvider::class),
            $c->make(LoggerInterface::class),
            // Modules that use the fetched SERPs (competitors, AI Overviews) register with SerpObservers.
            $c->make(SerpObserver::class),
        ));
        $container->singleton(RankingService::class, static fn(Container $c): RankingService => new RankingService(
            $c->make(RankingRepository::class),
            $c->make(KeywordRepository::class),
            $c->make(SerpProvider::class),
            $c->make(RankCheckScheduler::class),
            $c->make(ProjectService::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(SearchPerformanceReader::class),
            $c->make(FeatureManager::class),
        ));
        $container->singleton(RankingsController::class, static fn(Container $c): RankingsController => new RankingsController(
            $c->make(ProjectService::class),
            $c->make(RankingService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(Clock::class),
            $c->make(\SeoControlCenter\Modules\Annotations\AnnotationService::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $path = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/rankings';

        $routes->get($path, RankingsController::class, 'show', 'projects.rankings');
        $routes->post($path . '/settings', RankingsController::class, 'settings');
        $routes->post($path . '/check', RankingsController::class, 'check');
        $routes->get($path . '/export', RankingsController::class, 'export', 'projects.rankings.export');
        $routes->get($path . '/{keyword:' . Uuid::ROUTE_PATTERN . '}', RankingsController::class, 'keyword', 'projects.rankings.keyword');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(CheckRankingsJob::TYPE, static fn(): CheckRankingsJob => new CheckRankingsJob(
            $container->make(RankChecker::class),
            $container->make(Connection::class),
            $container->make(Queue::class),
        ));
        $jobs->register(ScheduleRankChecksJob::TYPE, static fn(): ScheduleRankChecksJob => new ScheduleRankChecksJob(
            $container->make(RankingRepository::class),
            $container->make(RankCheckScheduler::class),
            $container->make(Clock::class),
        ));
        $jobs->requireFeature(CheckRankingsJob::TYPE, Feature::RankTracking);
        $jobs->requireFeature(ScheduleRankChecksJob::TYPE, Feature::RankTracking);
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // Only projects where someone switched tracking on are checked (paid).
        $scheduler->add(new ScheduledTask('rankings.schedule', '41 * * * *', ScheduleRankChecksJob::TYPE, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
