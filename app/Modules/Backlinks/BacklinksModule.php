<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks;

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
use SeoControlCenter\Domain\Backlinks\BacklinksProvider;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Backlinks\Controllers\BacklinksController;
use SeoControlCenter\Modules\Backlinks\Jobs\BacklinksJobs;
use SeoControlCenter\Modules\Backlinks\Jobs\FetchBacklinksJob;
use SeoControlCenter\Modules\Backlinks\Jobs\ScheduleBacklinkChecksJob;
use SeoControlCenter\Modules\Backlinks\Persistence\PdoBacklinkHistory;
use SeoControlCenter\Modules\Research\ResearchRepository;

/**
 * Backlinks (roadmap 1.3): link profiles of the project and its competitors,
 * referring domains, new and lost links, anchors and the link gap.
 * Registered after the research module, whose result store it uses.
 */
final class BacklinksModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'backlinks';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(BacklinkHistory::class, static fn(Container $c): BacklinkHistory => new PdoBacklinkHistory(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(BacklinksScheduler::class, static fn(Container $c): BacklinksScheduler => new BacklinksJobs($c->make(Queue::class)));
        $container->singleton(BacklinksService::class, static fn(Container $c): BacklinksService => new BacklinksService(
            $c->make(ResearchRepository::class),
            $c->make(BacklinksScheduler::class),
            $c->make(BacklinksProvider::class),
            $c->make(BacklinkHistory::class),
            $c->make(CompetitorRepository::class),
            $c->make(ProjectService::class),
            $c->make(ProjectRepository::class),
            $c->make(FeatureManager::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
        ));
        $container->singleton(BacklinksController::class, static fn(Container $c): BacklinksController => new BacklinksController(
            $c->make(BacklinksService::class),
            $c->make(ProjectService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/backlinks';
        $routes->get($base, BacklinksController::class, 'show', 'projects.backlinks');
        $routes->post($base . '/fetch', BacklinksController::class, 'fetch');
        $routes->post($base . '/monthly', BacklinksController::class, 'monthly');
        $routes->post($base . '/monitoring', BacklinksController::class, 'monitoring');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(BacklinksJobs::FETCH, static fn(): FetchBacklinksJob => new FetchBacklinksJob($container->make(BacklinksService::class), $container->make(Connection::class)));
        $jobs->register(BacklinksJobs::SCHEDULE, static fn(): ScheduleBacklinkChecksJob => new ScheduleBacklinkChecksJob($container->make(BacklinksService::class)));
        foreach ([BacklinksJobs::FETCH, BacklinksJobs::SCHEDULE] as $type) {
            $jobs->requireFeature($type, Feature::Backlinks);
        }
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // Daily; projects with the monthly check on get one every 30 days.
        $scheduler->add(new ScheduledTask('backlinks.schedule', '13 5 * * *', BacklinksJobs::SCHEDULE, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
