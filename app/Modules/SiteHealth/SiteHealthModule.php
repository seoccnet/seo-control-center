<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
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
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;
use SeoControlCenter\Modules\SiteHealth\Controllers\SiteHealthController;
use SeoControlCenter\Modules\SiteHealth\Jobs\SiteHealthJob;
use SeoControlCenter\Modules\SiteHealth\Jobs\SiteHealthJobs;
use SeoControlCenter\Modules\SiteHealth\Persistence\PdoSiteHealthRepository;

/** Site health monitoring (1.3.0): uptime, certificate, domain, robots.txt and sitemap. */
final class SiteHealthModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'site-health';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(SiteHealthRepository::class, static fn(Container $c): SiteHealthRepository => new PdoSiteHealthRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(SiteHealthScheduler::class, static fn(Container $c): SiteHealthScheduler => new SiteHealthJobs($c->make(Queue::class)));
        $container->singleton(SiteProbe::class, static fn(Container $c): SiteProbe => new CrawlerSiteProbe($c->make(HttpClient::class), $c->make(SiteCrawler::class)));
        $container->singleton(CertificateProbe::class, static fn(Container $c): CertificateProbe => new TlsCertificateProbe($c->make(HostResolver::class)));
        $container->singleton(DomainExpiryLookup::class, static fn(Container $c): DomainExpiryLookup => new RdapDomainLookup($c->make(HttpClient::class), $c->make(Clock::class), $c->make(Paths::class)->storage('cache')));
        $container->singleton(SiteHealthService::class, static fn(Container $c): SiteHealthService => new SiteHealthService(
            $c->make(SiteHealthRepository::class),
            $c->make(SiteHealthScheduler::class),
            $c->make(SiteProbe::class),
            $c->make(CertificateProbe::class),
            $c->make(DomainExpiryLookup::class),
            $c->make(Authorizer::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
        ));
        $container->singleton(SiteHealthController::class, static fn(Container $c): SiteHealthController => new SiteHealthController($c->make(ProjectService::class), $c->make(SiteHealthService::class), $c->make(ResponseFactory::class), $c->make(ViewRenderer::class)));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/site-health';
        $routes->get($base, SiteHealthController::class, 'index', 'site_health.index');
        $routes->post($base . '/settings', SiteHealthController::class, 'settings', 'site_health.settings');
        $routes->post($base . '/check', SiteHealthController::class, 'checkNow', 'site_health.check');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(SiteHealthJobs::DISPATCH, static fn(): SiteHealthJob => new SiteHealthJob($container->make(SiteHealthService::class), true));
        $jobs->register(SiteHealthJobs::CHECK, static fn(): SiteHealthJob => new SiteHealthJob($container->make(SiteHealthService::class), false));
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask(SiteHealthJobs::DISPATCH, '*/15 * * * *', SiteHealthJobs::DISPATCH, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
