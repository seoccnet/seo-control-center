<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SerpHistory;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Domain\SerpHistory\SerpEventRepository;
use SeoControlCenter\Modules\Rankings\SerpObservers;
use SeoControlCenter\Modules\SerpHistory\Persistence\PdoSerpEventRepository;

/**
 * Results-page change history (ADR-039, E1), derived from stored rank
 * checks. Nothing here buys data.
 */
final class SerpHistoryModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'serp_history';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(SerpEventRepository::class, static fn(Container $c): SerpEventRepository => new PdoSerpEventRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(SerpHistoryService::class, static fn(Container $c): SerpHistoryService => new SerpHistoryService(
            $c->make(SerpEventRepository::class),
            $c->make(RankingRepository::class),
            $c->make(CompetitorRepository::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
        ));
        $container->singleton(SerpHistoryController::class, static fn(Container $c): SerpHistoryController => new SerpHistoryController(
            $c->make(ProjectService::class),
            $c->make(SerpHistoryService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->make(SerpObservers::class)->add(static fn(): SerpObserver => new SerpHistoryRecorder($container->make(SerpHistoryService::class)));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/projects/{project:' . Uuid::ROUTE_PATTERN . '}/rankings/changes', SerpHistoryController::class, 'show', 'projects.rankings.changes');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(CatchUpJob::TYPE, static fn(): CatchUpJob => new CatchUpJob($container->make(SerpHistoryService::class)));
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // Free (stored checks only); after the hourly rank-check scheduling at :41.
        $scheduler->add(new ScheduledTask('serp_history.catch_up', '27 5 * * *', CatchUpJob::TYPE, 'analysis'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
