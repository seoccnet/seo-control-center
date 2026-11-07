<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Client\HostResolver;
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
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\SiteAudit\Controllers\SiteAuditController;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;
use SeoControlCenter\Modules\SiteAudit\Jobs\CrawlSiteAuditJob;
use SeoControlCenter\Modules\SiteAudit\Jobs\DispatchSiteAuditsJob;
use SeoControlCenter\Modules\SiteAudit\Jobs\SiteAuditJobs;
use SeoControlCenter\Modules\SiteAudit\Persistence\PdoSiteAuditRepository;

/**
 * Site audit (roadmap 1.2): crawls a project's own site in the background
 * and reports broken pages, redirects and on-page problems.
 */
final class SiteAuditModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'site_audit';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(SiteAuditRepository::class, static fn(Container $c): SiteAuditRepository => new PdoSiteAuditRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(SiteAuditScheduler::class, static fn(Container $c): SiteAuditScheduler => new SiteAuditJobs($c->make(Queue::class)));
        // Internal link graph and suggestions (ADR-040, F1).
        $container->singleton(\SeoControlCenter\Domain\InternalLinks\LinkGraphRepository::class, static fn(Container $c): \SeoControlCenter\Domain\InternalLinks\LinkGraphRepository => new Persistence\PdoLinkGraphRepository($c->make(Connection::class)));
        $container->singleton(LinkSuggestionService::class, static fn(Container $c): LinkSuggestionService => new LinkSuggestionService(
            $c->make(\SeoControlCenter\Domain\InternalLinks\LinkGraphRepository::class),
            $c->make(SiteAuditRepository::class),
            $c->make(ProjectRepository::class),
            $c->make(Clock::class),
            $c->has(\SeoControlCenter\Domain\Clusters\ClusterRepository::class) ? $c->make(\SeoControlCenter\Domain\Clusters\ClusterRepository::class) : null,
        ));
        $container->singleton(LinkSuggestionScheduler::class, static fn(Container $c): LinkSuggestionScheduler => new Jobs\LinkSuggestionJobs($c->make(\SeoControlCenter\Domain\InternalLinks\LinkGraphRepository::class), $c->make(Queue::class), $c->make(Clock::class)));
        $container->singleton(Controllers\InternalLinksController::class, static fn(Container $c): Controllers\InternalLinksController => new Controllers\InternalLinksController($c->make(LinkSuggestionService::class), $c->make(ProjectService::class), $c->make(ResponseFactory::class), $c->make(ViewRenderer::class)));
        $container->singleton(SiteCrawler::class, static fn(Container $c): SiteCrawler => new SiteCrawler(
            $c->make(HttpClient::class),
            $c->make(HostResolver::class),
        ));
        $container->singleton(SiteAuditService::class, static fn(Container $c): SiteAuditService => new SiteAuditService(
            $c->make(SiteAuditRepository::class),
            $c->make(SiteAuditScheduler::class),
            $c->make(SiteCrawler::class),
            $c->make(ProjectService::class),
            $c->make(ProjectRepository::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(FeatureManager::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
            $c->make(LinkSuggestionScheduler::class),
        ));
        $container->singleton(RenderedPageProvider::class, static fn(Container $c): RenderedPageProvider => new RenderedPageProvider($c->make(\SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient::class)));
        $container->singleton(RenderedAuditService::class, static fn(Container $c): RenderedAuditService => new RenderedAuditService(
            $c->make(\SeoControlCenter\Modules\Research\ResearchRepository::class),
            $c->make(RenderedPageProvider::class),
            $c->make(SiteAuditScheduler::class),
            $c->make(\SeoControlCenter\Modules\Pages\PageRepository::class),
            $c->make(ProjectRepository::class),
            $c->make(ProjectService::class),
            $c->make(\SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository::class),
            $c->make(FeatureManager::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(HostResolver::class),
            $c->make(SiteCrawler::class),
        ));
        $container->singleton(SiteAuditController::class, static fn(Container $c): SiteAuditController => new SiteAuditController(
            $c->make(SiteAuditService::class),
            $c->make(ProjectService::class),
            $c->make(FeatureManager::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(\SeoControlCenter\Modules\WebVitals\WebVitalsRepository::class),
            $c->make(RenderedAuditService::class),
            $c->has(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader::class) ? new TitleTriage($c->make(SiteAuditRepository::class), $c->make(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader::class)) : null,
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/site-audit';
        $audit = $base . '/{audit:' . Uuid::ROUTE_PATTERN . '}';
        $routes->get($base, SiteAuditController::class, 'show', 'projects.audit');
        $routes->post($base, SiteAuditController::class, 'start');
        $routes->post($base . '/settings', SiteAuditController::class, 'settings');
        $routes->post($base . '/render', SiteAuditController::class, 'render');
        $routes->get($base . '/links', Controllers\InternalLinksController::class, 'index', 'projects.audit.links');
        $routes->get($audit, SiteAuditController::class, 'show', 'projects.audit.show');
        $routes->get($audit . '/pages', SiteAuditController::class, 'pages', 'projects.audit.pages');
        $routes->get($audit . '/export', SiteAuditController::class, 'export', 'projects.audit.export');
        $routes->post($audit . '/cancel', SiteAuditController::class, 'cancel');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(SiteAuditJobs::CRAWL, static fn(): CrawlSiteAuditJob => new CrawlSiteAuditJob(
            $container->make(SiteAuditService::class),
            $container->make(Connection::class),
            $container->make(Queue::class),
        ));
        $jobs->register(SiteAuditJobs::DISPATCH, static fn(): DispatchSiteAuditsJob => new DispatchSiteAuditsJob($container->make(SiteAuditService::class)));
        $jobs->register(SiteAuditJobs::RENDER, static fn(): Jobs\RenderSiteAuditJob => new Jobs\RenderSiteAuditJob(
            $container->make(RenderedAuditService::class),
            $container->make(SiteAuditScheduler::class),
            $container->make(Connection::class),
        ));
        foreach ([Jobs\LinkSuggestionJobs::BUILD, Jobs\LinkSuggestionJobs::DAILY] as $type) {
            $jobs->register($type, static fn(): Jobs\LinkSuggestionJobs => new Jobs\LinkSuggestionJobs(
                $container->make(\SeoControlCenter\Domain\InternalLinks\LinkGraphRepository::class),
                $container->make(Queue::class),
                $container->make(Clock::class),
                $container->make(LinkSuggestionService::class),
            ));
        }
        foreach ([SiteAuditJobs::CRAWL, SiteAuditJobs::DISPATCH, SiteAuditJobs::RENDER, Jobs\LinkSuggestionJobs::BUILD, Jobs\LinkSuggestionJobs::DAILY] as $type) {
            $jobs->requireFeature($type, Feature::SiteAudit);
        }
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // Daily; each project with the weekly audit on gets one every 7 days.
        $scheduler->add(new ScheduledTask('site_audit.dispatch', '43 2 * * *', SiteAuditJobs::DISPATCH, SiteAuditJobs::QUEUE));
        // Daily, after the 6-hourly opportunity analysis may have changed topic owners.
        $scheduler->add(new ScheduledTask('site_audit.link_suggestions', '17 4 * * *', Jobs\LinkSuggestionJobs::DAILY, SiteAuditJobs::QUEUE));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
