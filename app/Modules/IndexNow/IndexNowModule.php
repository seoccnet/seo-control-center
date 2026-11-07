<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
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
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\IndexNow\Api\IndexNowClient;
use SeoControlCenter\Modules\IndexNow\Controllers\IndexNowController;
use SeoControlCenter\Modules\IndexNow\Jobs\IndexNowJobs;
use SeoControlCenter\Modules\IndexNow\Jobs\SitemapIndexNowJob;
use SeoControlCenter\Modules\IndexNow\Jobs\SubmitIndexNowJob;
use SeoControlCenter\Modules\IndexNow\Jobs\SyncIndexNowJob;
use SeoControlCenter\Modules\IndexNow\Persistence\PdoIndexNowRepository;

/**
 * IndexNow (roadmap 1.1): notifies Bing and the other IndexNow engines of
 * new and changed URLs, by hand or from the project's sitemap.
 */
final class IndexNowModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'indexnow';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(IndexNowRepository::class, static fn(Container $c): IndexNowRepository => new PdoIndexNowRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(IndexNowScheduler::class, static fn(Container $c): IndexNowScheduler => new IndexNowJobs(
            $c->make(Queue::class),
            $c->make(Clock::class),
        ));
        $container->singleton(IndexNowClient::class, static fn(Container $c): IndexNowClient => new IndexNowClient(
            $c->make(HttpClient::class),
            $c->make(HostResolver::class),
        ));
        $container->singleton(IndexNowService::class, static fn(Container $c): IndexNowService => new IndexNowService(
            $c->make(IndexNowRepository::class),
            $c->make(IndexNowClient::class),
            $c->make(IndexNowScheduler::class),
            $c->make(Authorizer::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(FeatureManager::class),
            $c->make(LoggerInterface::class),
        ));
        $container->singleton(IndexNowController::class, static fn(Container $c): IndexNowController => new IndexNowController(
            $c->make(IndexNowService::class),
            $c->make(ProjectService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(Paths::class)->base,
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/indexnow';
        $routes->get($base, IndexNowController::class, 'show', 'projects.indexnow');
        $routes->post($base . '/setup', IndexNowController::class, 'setUp');
        $routes->post($base . '/verify', IndexNowController::class, 'verify');
        $routes->post($base . '/sitemap', IndexNowController::class, 'sitemap');
        $routes->post($base . '/submit', IndexNowController::class, 'submit');
        $routes->get($base . '/key-file', IndexNowController::class, 'keyFile', 'projects.indexnow.key_file');
        $routes->get($base . '/wordpress-plugin', IndexNowController::class, 'wordpressPlugin', 'projects.indexnow.wordpress_plugin');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(IndexNowJobs::SUBMIT, static fn(): SubmitIndexNowJob => new SubmitIndexNowJob($container->make(IndexNowService::class)));
        $jobs->register(IndexNowJobs::SITEMAP, static fn(): SitemapIndexNowJob => new SitemapIndexNowJob(
            $container->make(IndexNowService::class),
            $container->make(Connection::class),
        ));
        $jobs->register(IndexNowJobs::SYNC, static fn(): SyncIndexNowJob => new SyncIndexNowJob(
            $container->make(IndexNowRepository::class),
            $container->make(IndexNowScheduler::class),
        ));
        foreach ([IndexNowJobs::SUBMIT, IndexNowJobs::SITEMAP, IndexNowJobs::SYNC] as $type) {
            $jobs->requireFeature($type, Feature::BingWebmaster);
        }
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // Sitemaps are read every 6 hours; new and changed URLs go out right after.
        $scheduler->add(new ScheduledTask('indexnow.sync', '19 */6 * * *', IndexNowJobs::SYNC, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
