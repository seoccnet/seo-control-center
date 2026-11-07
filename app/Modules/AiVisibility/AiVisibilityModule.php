<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility;

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
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Modules\AiVisibility\Controllers\AiVisibilityController;
use SeoControlCenter\Modules\AiVisibility\Persistence\PdoAiCrawlerRepository;
use SeoControlCenter\Modules\AiVisibility\Persistence\PdoAiOverviewRepository;
use SeoControlCenter\Modules\Analytics\AiReferralService;
use SeoControlCenter\Modules\Rankings\SerpObservers;
use SeoControlCenter\Modules\Research\ResearchService;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * AI visibility (roadmap 1.2): Google AI Overviews for the tracked keywords,
 * taken from the SERPs rank checks already fetch, and the AI assistant
 * mentions looked up through the research module. Registered after the
 * rankings and competitors modules.
 *
 * Readiness (0.43.0): which AI crawlers robots.txt lets in (a free weekly
 * check of the site's own robots.txt and llms.txt), how quotable the top
 * pages are (from the site audit) and the visits AI assistants send (GA4).
 */
final class AiVisibilityModule implements Module, ProvidesJobs
{
    public const string DISPATCH_JOB = 'ai_crawlers.dispatch';

    public function name(): string
    {
        return 'ai_visibility';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(AiOverviewRepository::class, static fn(Container $c): AiOverviewRepository => new PdoAiOverviewRepository(
            $c->make(Connection::class),
        ));
        $container->singleton(AiOverviewTracker::class, static fn(Container $c): AiOverviewTracker => new AiOverviewTracker(
            $c->make(AiOverviewRepository::class),
        ));
        $container->make(SerpObservers::class)->add(static fn(): SerpObserver => $container->make(AiOverviewTracker::class));
        $container->singleton(AiVisibilityService::class, static fn(Container $c): AiVisibilityService => new AiVisibilityService(
            $c->make(AiOverviewRepository::class),
            $c->make(RankingRepository::class),
            $c->make(CompetitorRepository::class),
            $c->has(SiteAuditRepository::class) ? $c->make(SiteAuditRepository::class) : null,
            $c->has(SearchPerformanceReader::class) ? $c->make(SearchPerformanceReader::class) : null,
            $c->make(Clock::class),
        ));
        $container->singleton(AiCrawlerRepository::class, static fn(Container $c): AiCrawlerRepository => new PdoAiCrawlerRepository($c->make(Connection::class)));
        $container->singleton(AiCrawlerScheduler::class, static fn(Container $c): AiCrawlerScheduler => new QueuedAiCrawlerScheduler($c->make(Queue::class), $c->make(Clock::class)));
        $container->singleton(SiteFileFetcher::class, static fn(Container $c): SiteFileFetcher => new SiteFileFetcher($c->make(HttpClient::class), $c->make(HostResolver::class)));
        $container->singleton(AiCrawlerService::class, static fn(Container $c): AiCrawlerService => new AiCrawlerService(
            $c->make(AiCrawlerRepository::class),
            $c->make(AiCrawlerScheduler::class),
            $c->make(SiteFileFetcher::class),
            $c->make(ProjectRepository::class),
            $c->make(Authorizer::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->has(SearchPerformanceReader::class) ? $c->make(SearchPerformanceReader::class) : null,
        ));
        $container->singleton(AiVisibilityController::class, static fn(Container $c): AiVisibilityController => new AiVisibilityController(
            $c->make(AiVisibilityService::class),
            $c->make(ResearchService::class),
            $c->make(ProjectService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(\SeoControlCenter\Modules\Research\BrandMentionService::class),
            $c->make(AiCrawlerService::class),
            $c->has(AiReferralService::class) ? $c->make(AiReferralService::class) : null,
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/projects/{project:' . Uuid::ROUTE_PATTERN . '}/ai-visibility', AiVisibilityController::class, 'show', 'projects.ai_visibility');
        $routes->post('/projects/{project:' . Uuid::ROUTE_PATTERN . '}/ai-visibility/brand', AiVisibilityController::class, 'saveBrand');
        $routes->post('/projects/{project:' . Uuid::ROUTE_PATTERN . '}/ai-visibility/brand/fetch', AiVisibilityController::class, 'fetchBrand');
        $routes->post('/projects/{project:' . Uuid::ROUTE_PATTERN . '}/ai-visibility/crawlers/check', AiVisibilityController::class, 'checkCrawlers', 'projects.ai_visibility.crawlers');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(QueuedAiCrawlerScheduler::JOB, static fn(): Jobs\CheckAiCrawlersJob => new Jobs\CheckAiCrawlersJob($container->make(AiCrawlerService::class)));
        $jobs->register(self::DISPATCH_JOB, static fn(): Jobs\DispatchAiCrawlerChecksJob => new Jobs\DispatchAiCrawlerChecksJob($container->make(AiCrawlerService::class)));
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask(self::DISPATCH_JOB, '23 5 * * *', self::DISPATCH_JOB, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
