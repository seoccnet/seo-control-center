<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Forecasting;

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Support\Uuid;

final class ForecastingModule implements Module, \SeoControlCenter\Core\Modules\ProvidesJobs
{
    public function name(): string
    {
        return 'forecasting';
    }
    public function registerServices(Container $container): void
    {
        $container->singleton(ForecastEngine::class, static fn(): ForecastEngine => new ForecastEngine());
        $container->singleton(ForecastCalibration::class, static fn(): ForecastCalibration => new ForecastCalibration());
        $container->singleton(ForecastRepository::class, static fn(Container $c): ForecastRepository => new Persistence\PdoForecastRepository($c->make(\SeoControlCenter\Core\Database\Connection::class), $c->make(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader::class), $c->make(\SeoControlCenter\Modules\Analytics\Ga4Repository::class), $c->make(\SeoControlCenter\Core\Support\Clock::class)));
        $container->singleton(ForecastService::class, static fn(Container $c): ForecastService => new ForecastService($c->make(ForecastRepository::class), $c->make(ForecastEngine::class), $c->make(ForecastCalibration::class), $c->make(\SeoControlCenter\Core\Support\Clock::class), $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class), $c->make(\SeoControlCenter\Core\Audit\AuditLogger::class)));
        $container->singleton(ForecastController::class, static fn(Container $c): ForecastController => new ForecastController($c->make(\SeoControlCenter\Domain\Projects\ProjectService::class), $c->make(ForecastService::class), $c->make(\SeoControlCenter\Core\Http\ResponseFactory::class), $c->make(\SeoControlCenter\Core\View\ViewRenderer::class)));
    }
    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/forecasts';
        $routes->get($base, ForecastController::class, 'index', 'forecasts.index');
        $routes->post($base, ForecastController::class, 'capture', 'forecasts.capture');
        $routes->post($base . '/calendar', ForecastController::class, 'calendar', 'forecasts.calendar');
        $routes->post($base . '/tracking', ForecastController::class, 'tracking', 'forecasts.tracking');
        $routes->post($base . '/calendar/exclude', ForecastController::class, 'excludeEvent', 'forecasts.calendar.exclude');
    }
    public function migrationPaths(): array
    {
        return [];
    }
    public function registerJobs(\SeoControlCenter\Core\Queue\JobRegistry $jobs, Container $container): void
    {
        $jobs->register('forecast.dispatch', static fn(): ForecastDispatchJob => new ForecastDispatchJob($container->make(ForecastRepository::class), $container->make(\SeoControlCenter\Core\Queue\Queue::class), $container->make(\SeoControlCenter\Core\Support\Clock::class)));
        $jobs->register('forecast.review', static fn(): ForecastReviewJob => new ForecastReviewJob($container->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class), $container->make(ForecastService::class)));
    }
    public function registerSchedule(\SeoControlCenter\Core\Queue\Scheduler $scheduler): void
    {
        $scheduler->add(new \SeoControlCenter\Core\Queue\ScheduledTask('forecast.dispatch', '19 * * * *', 'forecast.dispatch', 'analysis'));
    }
}
