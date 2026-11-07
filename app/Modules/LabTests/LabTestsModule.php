<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\LabTests;

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Http\Client\CurlHttpClient;
use SeoControlCenter\Core\Http\Client\HostResolver;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\LabTests\LabTestProviders;
use SeoControlCenter\Domain\LabTests\LabTestRepository;

/**
 * Saved lab test profiles with repeat runs from PageSpeed Insights (free)
 * and DataForSEO Lighthouse (paid), shown beside CrUX field data (ADR-040, F2).
 */
final class LabTestsModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'lab_tests';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(LabTestRepository::class, static fn(Container $c): LabTestRepository => new PdoLabTestRepository($c->make(\SeoControlCenter\Core\Database\Connection::class), $c->make(Clock::class)));
        $container->singleton(LabTestProviders::class, static function (Container $c): LabTestProviders {
            // PageSpeed can take up to a minute: its own client with a longer timeout (tests keep their fake).
            $http = $c->make(HttpClient::class);
            if ($http instanceof CurlHttpClient) {
                $http = new CurlHttpClient(60, 'SEO-Control-Center/' . $c->get('app.version'), $c->make(HostResolver::class));
            }

            return new LabTestClients($http, $c->has(\SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient::class) ? $c->make(\SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient::class) : null);
        });
        $container->singleton(LabTestScheduler::class, static fn(Container $c): LabTestScheduler => new LabTestJobs($c->make(Queue::class)));
        $container->singleton(LabTestService::class, static fn(Container $c): LabTestService => new LabTestService(
            $c->make(LabTestRepository::class),
            $c->make(LabTestProviders::class),
            $c->make(LabTestScheduler::class),
            $c->make(\SeoControlCenter\Modules\WebVitals\WebVitalsRepository::class),
            $c->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class),
            $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class),
            $c->make(\SeoControlCenter\Core\Features\FeatureManager::class),
            $c->make(\SeoControlCenter\Core\RateLimit\RateLimiter::class),
            $c->make(\SeoControlCenter\Core\Audit\AuditLogger::class),
            $c->make(Clock::class),
        ));
        $container->singleton(LabTestsController::class, static fn(Container $c): LabTestsController => new LabTestsController(
            $c->make(LabTestService::class),
            $c->make(\SeoControlCenter\Domain\Projects\ProjectService::class),
            $c->make(\SeoControlCenter\Core\Http\ResponseFactory::class),
            $c->make(\SeoControlCenter\Core\View\ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/lab-tests';
        $profile = $base . '/{profile:' . Uuid::ROUTE_PATTERN . '}';
        $routes->get($base, LabTestsController::class, 'index', 'projects.lab-tests');
        $routes->post($base, LabTestsController::class, 'add', 'projects.lab-tests.add');
        $routes->post($profile . '/run', LabTestsController::class, 'run', 'projects.lab-tests.run');
        $routes->post($profile . '/weekly', LabTestsController::class, 'weekly', 'projects.lab-tests.weekly');
        $routes->post($profile . '/delete', LabTestsController::class, 'delete', 'projects.lab-tests.delete');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        foreach ([LabTestJobs::RUN, LabTestJobs::SCHEDULE] as $type) {
            $jobs->register($type, static fn(): LabTestJobs => new LabTestJobs($container->make(Queue::class), $container->make(LabTestService::class)));
            $jobs->requireFeature($type, Feature::SiteAudit);
        }
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask('lab_tests.schedule', '29 * * * *', LabTestJobs::SCHEDULE, LabTestJobs::QUEUE));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
