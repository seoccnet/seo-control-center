<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Branding\CurrentBranding;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Mail\Mailer;
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
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Modules\Bing\BingService;
use SeoControlCenter\Modules\Reports\Controllers\ReportsController;
use SeoControlCenter\Modules\Reports\Jobs\DispatchReportsJob;
use SeoControlCenter\Modules\Reports\Jobs\ReportJobs;
use SeoControlCenter\Modules\Reports\Jobs\SendReportJob;
use SeoControlCenter\Modules\Reports\Persistence\PdoReportScheduleRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * Scheduled reports (roadmap 1.1): weekly or monthly e-mails per project
 * and the printable report page. Registered after the modules whose data
 * the report reads.
 */
final class ReportsModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'reports';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(ReportScheduleRepository::class, static fn(Container $c): ReportScheduleRepository => new PdoReportScheduleRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(ReportScheduler::class, static fn(Container $c): ReportScheduler => new ReportJobs($c->make(Queue::class)));
        $container->singleton(ReportCustomization::class, static fn(Container $c): ReportCustomization => new ReportCustomization($c->make(ReportScheduleRepository::class), $c->make(Authorizer::class), $c->make(AuditLogger::class), $c->make(CurrentBranding::class)));
        $container->singleton(ReportBuilder::class, static fn(Container $c): ReportBuilder => new ReportBuilder(
            $c->make(SearchPerformanceReader::class),
            $c->make(BingService::class),
            $c->make(RankingRepository::class),
            $c->make(OpportunityRepository::class),
            $c->make(SiteAuditRepository::class),
            $c->make(\SeoControlCenter\Modules\Analytics\Ga4Repository::class),
            $c->make(\SeoControlCenter\Modules\Research\ResearchRepository::class),
        ));
        $container->singleton(ReportService::class, static fn(Container $c): ReportService => new ReportService(
            $c->make(ReportScheduleRepository::class),
            $c->make(ReportScheduler::class),
            $c->make(ReportBuilder::class),
            $c->make(ProjectRepository::class),
            $c->make(Mailer::class),
            $c->make(Authorizer::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
            $c->make(ViewRenderer::class),
            $c->make(Config::class)->string('app.url'),
            $c->make(ReportCustomization::class),
            $c->make(\SeoControlCenter\Core\Support\Paths::class)->base . '/resources/fonts',
            $c->make(\SeoControlCenter\Modules\Branding\BrandingService::class),
        ));
        $container->singleton(ReportsController::class, static fn(Container $c): ReportsController => new ReportsController(
            $c->make(ReportService::class),
            $c->make(ReportBuilder::class),
            $c->make(ProjectService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(Clock::class),
            $c->make(ReportCustomization::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/reports';
        $routes->get($base, ReportsController::class, 'show', 'projects.reports');
        $routes->post($base, ReportsController::class, 'save');
        $routes->post($base . '/test', ReportsController::class, 'test');
        $routes->get($base . '/view', ReportsController::class, 'view', 'projects.reports.view');
        $routes->get($base . '/pdf', ReportsController::class, 'pdf', 'projects.reports.pdf');
        $routes->post($base . '/sections', ReportsController::class, 'sections');
        $routes->post($base . '/branding', ReportsController::class, 'branding');
        $routes->post($base . '/logo', ReportsController::class, 'uploadLogo');
        $routes->get($base . '/logo', ReportsController::class, 'logo');
        $routes->get($base . '/branding.css', ReportsController::class, 'stylesheet');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(ReportJobs::DISPATCH, static fn(): DispatchReportsJob => new DispatchReportsJob($container->make(ReportService::class)));
        $jobs->register(ReportJobs::SEND, static fn(): SendReportJob => new SendReportJob($container->make(ReportService::class)));
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // Hourly, so each project's 07:00 in its own time zone is met.
        $scheduler->add(new ScheduledTask('reports.dispatch', '11 * * * *', ReportJobs::DISPATCH, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
