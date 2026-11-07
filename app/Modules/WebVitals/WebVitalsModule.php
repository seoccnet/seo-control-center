<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\WebVitals;

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Support\Uuid;

final class WebVitalsModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'web_vitals';
    }
    public function registerServices(Container $container): void
    {
        $container->singleton(WebVitalsRepository::class, static fn(Container $c): WebVitalsRepository => new Persistence\PdoWebVitalsRepository($c->make(\SeoControlCenter\Core\Database\Connection::class), $c->make(\SeoControlCenter\Core\Crypto\Encrypter::class)));
        $container->singleton(\SeoControlCenter\Domain\WebVitals\WebVitalsProvider::class, static fn(Container $c): \SeoControlCenter\Domain\WebVitals\WebVitalsProvider => new CruxProvider($c->make(\SeoControlCenter\Core\Http\Client\HttpClient::class), $c->make(\SeoControlCenter\Core\Support\Clock::class)));
        $container->singleton(WebVitalsScheduler::class, static fn(Container $c): WebVitalsScheduler => new QueuedWebVitalsScheduler($c->make(\SeoControlCenter\Core\Queue\Queue::class), $c->make(\SeoControlCenter\Core\Support\Clock::class)));
        $container->singleton(WebVitalsService::class, static fn(Container $c): WebVitalsService => new WebVitalsService(
            $c->make(WebVitalsRepository::class),
            $c->make(\SeoControlCenter\Domain\WebVitals\WebVitalsProvider::class),
            $c->make(WebVitalsScheduler::class),
            $c->make(\SeoControlCenter\Modules\Pages\PageRepository::class),
            $c->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class),
            $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class),
            $c->make(\SeoControlCenter\Core\Features\FeatureManager::class),
            $c->make(\SeoControlCenter\Core\RateLimit\RateLimiter::class),
            $c->make(\SeoControlCenter\Core\Audit\AuditLogger::class),
            $c->make(\SeoControlCenter\Core\Support\Clock::class)
        ));
        $container->singleton(Controllers\WebVitalsController::class, static fn(Container $c): Controllers\WebVitalsController => new Controllers\WebVitalsController(
            $c->make(WebVitalsService::class),
            $c->make(WebVitalsRepository::class),
            $c->make(\SeoControlCenter\Domain\Projects\ProjectService::class),
            $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class),
            $c->make(\SeoControlCenter\Core\Http\ResponseFactory::class),
            $c->make(\SeoControlCenter\Core\View\ViewRenderer::class)
        ));
    }
    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/integrations/web-vitals', Controllers\WebVitalsController::class, 'show', 'integrations.web-vitals');
        $routes->post('/integrations/web-vitals', Controllers\WebVitalsController::class, 'save');
        $routes->post('/projects/{project:' . Uuid::ROUTE_PATTERN . '}/web-vitals', Controllers\WebVitalsController::class, 'fetch');
        $routes->post('/projects/{project:' . Uuid::ROUTE_PATTERN . '}/web-vitals/watch', Controllers\WebVitalsController::class, 'watch');
    }
    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register('web_vitals.fetch', static fn(): Jobs\FetchWebVitalsJob => new Jobs\FetchWebVitalsJob($container->make(WebVitalsService::class), $container->make(\SeoControlCenter\Core\Database\Connection::class)));
        $jobs->requireFeature('web_vitals.fetch', \SeoControlCenter\Core\Features\Feature::SiteAudit);
        $jobs->register('web_vitals.schedule', static fn(): Jobs\ScheduleWebVitalsJob => new Jobs\ScheduleWebVitalsJob($container->make(WebVitalsService::class), $container->make(\SeoControlCenter\Core\Database\Connection::class)));
        $jobs->requireFeature('web_vitals.schedule', \SeoControlCenter\Core\Features\Feature::SiteAudit);
    }
    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new \SeoControlCenter\Core\Queue\ScheduledTask('web_vitals.schedule', '47 * * * *', 'web_vitals.schedule', 'analysis'));
    }
    public function migrationPaths(): array
    {
        return [];
    }
}
