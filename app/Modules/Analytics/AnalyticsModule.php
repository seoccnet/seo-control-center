<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics;

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Support\Uuid;

final class AnalyticsModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'analytics';
    }
    public function registerServices(Container $container): void
    {
        $container->singleton(Ga4Repository::class, static fn(Container $c): Ga4Repository => new Persistence\PdoGa4Repository($c->make(\SeoControlCenter\Core\Database\Connection::class), $c->make(\SeoControlCenter\Core\Crypto\Encrypter::class), $c->make(\SeoControlCenter\Core\Support\Clock::class)));
        $container->singleton(\SeoControlCenter\Domain\Analytics\Ga4Provider::class, static fn(Container $c): \SeoControlCenter\Domain\Analytics\Ga4Provider => new GoogleGa4Provider($c->make(\SeoControlCenter\Core\Http\Client\HttpClient::class), $c->make(\SeoControlCenter\Core\Support\Clock::class)));
        $container->singleton(Ga4Scheduler::class, static fn(Container $c): Ga4Scheduler => new QueuedGa4Scheduler($c->make(\SeoControlCenter\Core\Queue\Queue::class), $c->make(\SeoControlCenter\Core\Support\Clock::class)));
        $container->singleton(Ga4Service::class, static fn(Container $c): Ga4Service => new Ga4Service(
            $c->make(Ga4Repository::class),
            $c->make(\SeoControlCenter\Domain\Analytics\Ga4Provider::class),
            $c->make(Ga4Scheduler::class),
            $c->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class),
            $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class),
            $c->make(\SeoControlCenter\Core\Features\FeatureManager::class),
            $c->make(\SeoControlCenter\Core\RateLimit\RateLimiter::class),
            $c->make(\SeoControlCenter\Core\Audit\AuditLogger::class),
            $c->make(\SeoControlCenter\Core\Support\Clock::class),
            static function (\SeoControlCenter\Domain\Projects\Project $project, \SeoControlCenter\Domain\Analytics\Ga4Credentials $credentials, string $property, \SeoControlCenter\Domain\Shared\DateRange $range) use ($c): void {
                $c->make(BusinessMeasurementService::class)->importEvents($project, $credentials, $property, $range);
                $c->make(AiReferralService::class)->import($project, $credentials, $property, $range);
            },
        ));
        $container->singleton(AiReferralRepository::class, static fn(Container $c): AiReferralRepository => new Persistence\PdoAiReferralRepository($c->make(\SeoControlCenter\Core\Database\Connection::class), $c->make(\SeoControlCenter\Core\Support\Clock::class)));
        $container->singleton(AiReferralService::class, static fn(Container $c): AiReferralService => new AiReferralService(
            $c->make(AiReferralRepository::class),
            $c->make(Ga4Repository::class),
            $c->make(\SeoControlCenter\Domain\Analytics\Ga4Provider::class),
        ));
        $container->singleton(BusinessEventRepository::class, static fn(Container $c): BusinessEventRepository => new Persistence\PdoBusinessEventRepository($c->make(\SeoControlCenter\Core\Database\Connection::class), $c->make(\SeoControlCenter\Core\Support\Clock::class)));
        $container->singleton(BusinessMeasurementService::class, static fn(Container $c): BusinessMeasurementService => new BusinessMeasurementService(
            $c->make(BusinessEventRepository::class),
            $c->make(Ga4Repository::class),
            $c->make(\SeoControlCenter\Domain\Analytics\Ga4Provider::class),
            $c->make(Ga4Scheduler::class),
            $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class),
            $c->make(\SeoControlCenter\Core\RateLimit\RateLimiter::class),
            $c->make(\SeoControlCenter\Core\Audit\AuditLogger::class),
            $c->make(\SeoControlCenter\Core\Support\Clock::class),
            $c->has(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader::class) ? static function (int $projectId, \SeoControlCenter\Domain\Shared\DateRange $range) use ($c): array {
                $reader = $c->make(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader::class);

                return ['clicks' => $reader->totals($projectId, $range)->clicks, 'covered' => $reader->coveredDays($projectId, $range)];
            } : null,
        ));
        $container->singleton(Controllers\BusinessEventsController::class, static fn(Container $c): Controllers\BusinessEventsController => new Controllers\BusinessEventsController(
            $c->make(BusinessMeasurementService::class),
            $c->make(\SeoControlCenter\Domain\Projects\ProjectService::class),
            $c->make(\SeoControlCenter\Core\Http\ResponseFactory::class),
        ));
        $container->singleton(Controllers\Ga4Controller::class, static fn(Container $c): Controllers\Ga4Controller => new Controllers\Ga4Controller(
            $c->make(Ga4Service::class),
            $c->make(Ga4Repository::class),
            $c->make(\SeoControlCenter\Domain\Projects\ProjectService::class),
            $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class),
            $c->make(\SeoControlCenter\Core\Support\Clock::class),
            $c->make(\SeoControlCenter\Core\Http\ResponseFactory::class),
            $c->make(\SeoControlCenter\Core\View\ViewRenderer::class),
            $c->make(BusinessMeasurementService::class),
        ));
    }
    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/integrations/ga4', Controllers\Ga4Controller::class, 'settings', 'integrations.ga4');
        $routes->post('/integrations/ga4', Controllers\Ga4Controller::class, 'save');
        $project = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/analytics';
        $routes->get($project, Controllers\Ga4Controller::class, 'show', 'projects.analytics');
        $routes->post($project, Controllers\Ga4Controller::class, 'link');
        $routes->post($project . '/import', Controllers\Ga4Controller::class, 'import');
        $routes->post($project . '/events', Controllers\BusinessEventsController::class, 'add', 'projects.analytics.events');
        $routes->post($project . '/events/{event:' . Uuid::ROUTE_PATTERN . '}/delete', Controllers\BusinessEventsController::class, 'remove');
        $routes->post($project . '/events/check', Controllers\BusinessEventsController::class, 'check');
    }
    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register('ga4.import', static fn(): Jobs\ImportGa4Job => new Jobs\ImportGa4Job($container->make(Ga4Service::class), $container->make(\SeoControlCenter\Core\Database\Connection::class)));
        $jobs->register('ga4.sync', static fn(): Jobs\SyncGa4Job => new Jobs\SyncGa4Job($container->make(Ga4Service::class)));
        $jobs->requireFeature('ga4.import', \SeoControlCenter\Core\Features\Feature::SearchConsole);
        $jobs->requireFeature('ga4.sync', \SeoControlCenter\Core\Features\Feature::SearchConsole);
        $jobs->register(BusinessMeasurementService::CHECK_JOB, static fn(): Jobs\EventCheckJob => new Jobs\EventCheckJob($container->make(BusinessMeasurementService::class)));
        $jobs->requireFeature(BusinessMeasurementService::CHECK_JOB, \SeoControlCenter\Core\Features\Feature::SearchConsole);
    }
    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask('ga4.sync', '31 6 * * *', 'ga4.sync', 'default'));
    }
    public function migrationPaths(): array
    {
        return [];
    }
}
