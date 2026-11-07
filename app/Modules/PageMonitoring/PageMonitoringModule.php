<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring;

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Support\Uuid;

final class PageMonitoringModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'page_monitoring';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(PageMonitoringRepository::class, static fn(Container $c): PageMonitoringRepository => new PageMonitoringRepository($c->make(Connection::class)));
        $container->singleton(PageChangeReader::class, static fn(Container $c): PageChangeReader => $c->make(PageMonitoringRepository::class));
        $container->singleton(PageWatchReader::class, static fn(Container $c): PageWatchReader => $c->make(PageMonitoringRepository::class));
        $container->singleton(PageWatchWriter::class, static fn(Container $c): PageWatchWriter => $c->make(PageMonitoringRepository::class));
        $container->singleton(PageMonitoringService::class, static fn(Container $c): PageMonitoringService => new PageMonitoringService($c->make(PageMonitoringRepository::class), $c->make(\SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler::class), $c->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class), $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class), $c->make(\SeoControlCenter\Core\Features\FeatureManager::class), $c->make(\SeoControlCenter\Core\Audit\AuditLogger::class), $c->make(\SeoControlCenter\Core\Support\Clock::class), $c->make(\SeoControlCenter\Core\Queue\Queue::class), $c->make(\SeoControlCenter\Modules\Annotations\AnnotationService::class), $c->make(PageWatchWriter::class), $c->make(PageCheckScheduler::class)));
        $container->singleton(PageCheckScheduler::class, static fn(Container $c): PageCheckScheduler => new QueuedPageChecks($c->make(PageMonitoringRepository::class), $c->make(\SeoControlCenter\Core\Queue\Queue::class), $c->make(\SeoControlCenter\Core\Support\Clock::class)));
        $container->make(\SeoControlCenter\Modules\Annotations\AnnotationObservers::class)->add(static fn(): \SeoControlCenter\Modules\Annotations\AnnotationObserver => new PageMonitoringNoteObserver($container->make(PageMonitoringService::class)));
        $container->singleton(PageMonitoringController::class, static fn(Container $c): PageMonitoringController => new PageMonitoringController($c->make(\SeoControlCenter\Domain\Projects\ProjectService::class), $c->make(PageMonitoringService::class), $c->make(PageWatchReader::class), $c->make(\SeoControlCenter\Core\Http\ResponseFactory::class), $c->make(\SeoControlCenter\Core\View\ViewRenderer::class), $c->has(\SeoControlCenter\Modules\Pages\PageService::class) ? $c->make(\SeoControlCenter\Modules\Pages\PageService::class) : null));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/page-monitoring';
        $routes->get($base, PageMonitoringController::class, 'index', 'page_monitoring.index');
        $routes->post($base, PageMonitoringController::class, 'watch');
        $routes->post($base . '/bulk', PageMonitoringController::class, 'bulk', 'page_monitoring.bulk');
        $routes->post($base . '/limit', PageMonitoringController::class, 'limit', 'page_monitoring.limit');
        $routes->post($base . '/{change:' . Uuid::ROUTE_PATTERN . '}/annotation', PageMonitoringController::class, 'annotate');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        foreach (['check', 'schedule'] as $action) {
            $jobs->register('page_monitoring.' . $action, static fn(): PageMonitoringJob => new PageMonitoringJob($container->make(PageMonitoringService::class), $container->make(Connection::class), $action === 'schedule'));
            $jobs->requireFeature('page_monitoring.' . $action, \SeoControlCenter\Core\Features\Feature::SiteAudit);
        }
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask('page_monitoring.schedule', '17 * * * *', 'page_monitoring.schedule', 'analysis'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
