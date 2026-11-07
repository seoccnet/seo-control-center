<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
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
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Modules\Bing\BingDataRepository;
use SeoControlCenter\Modules\Pages\Controllers\PagesController;
use SeoControlCenter\Modules\Pages\Jobs\CleanupPageExportsJob;
use SeoControlCenter\Modules\Pages\Jobs\ExportPagesJob;
use SeoControlCenter\Modules\Pages\Jobs\PageExportJobs;
use SeoControlCenter\Modules\Pages\Persistence\PdoPageExportRepository;
use SeoControlCenter\Modules\Pages\Persistence\PdoPageRepository;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

final class PagesModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'pages';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(PageRepository::class, static fn(Container $c): PageRepository => new PdoPageRepository($c->make(Connection::class)));
        $container->singleton(PageInsights::class, static fn(): PageInsights => new PageInsights());
        $container->singleton(PageService::class, static fn(Container $c): PageService => new PageService(
            $c->make(PageRepository::class),
            $c->make(SearchPerformanceReader::class),
            $c->make(BingDataRepository::class),
            $c->make(SiteAuditRepository::class),
            $c->make(OpportunityRepository::class),
            $c->make(ResearchRepository::class),
            $c->make(PageInsights::class),
            $c->make(Clock::class),
            $c->make(\SeoControlCenter\Modules\WebVitals\WebVitalsRepository::class),
            $c->make(\SeoControlCenter\Modules\Analytics\Ga4Repository::class),
            $c->make(\SeoControlCenter\Modules\Annotations\AnnotationService::class),
            $c->make(\SeoControlCenter\Modules\Indexing\IndexingService::class),
            $c->has(\SeoControlCenter\Modules\Annotations\ResultsLedgerService::class) ? $c->make(\SeoControlCenter\Modules\Annotations\ResultsLedgerService::class) : null,
            $c->has(\SeoControlCenter\Domain\Comparison\AnalysisRepository::class) ? $c->make(\SeoControlCenter\Domain\Comparison\AnalysisRepository::class) : null,
        ));
        $container->singleton(PageExportRepository::class, static fn(Container $c): PageExportRepository => new PdoPageExportRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(PageExportScheduler::class, static fn(Container $c): PageExportScheduler => new PageExportJobs($c->make(Queue::class)));
        $container->singleton(PageExportDirectory::class, static fn(Container $c): PageExportDirectory => new PageExportDirectory($c->make(Paths::class)->storage('tmp/page-exports')));
        $container->singleton(PageExportService::class, static fn(Container $c): PageExportService => new PageExportService(
            $c->make(PageExportRepository::class),
            $c->make(PageRepository::class),
            $c->make(PageService::class),
            $c->make(PageExportScheduler::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
            $c->make(PageExportDirectory::class)->path,
        ));
        $container->singleton(PagesController::class, static fn(Container $c): PagesController => new PagesController($c->make(ProjectService::class), $c->make(PageService::class), $c->make(PageExportService::class), $c->make(ResponseFactory::class), $c->make(ViewRenderer::class)));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/pages';
        $routes->get($base, PagesController::class, 'index', 'pages.index');
        $routes->get($base . '/details', PagesController::class, 'show', 'pages.show');
        $routes->post($base . '/exports', PagesController::class, 'requestExport', 'pages.exports.create');
        $routes->get($base . '/exports/{export:' . Uuid::ROUTE_PATTERN . '}', PagesController::class, 'downloadExport', 'pages.exports.download');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(PageExportJobs::EXPORT, static fn(): ExportPagesJob => new ExportPagesJob($container->make(PageExportService::class)));
        $jobs->register(PageExportJobs::CLEANUP, static fn(): CleanupPageExportsJob => new CleanupPageExportsJob($container->make(PageExportService::class)));
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask('pages.exports.cleanup', '43 * * * *', PageExportJobs::CLEANUP, 'cleanup'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
