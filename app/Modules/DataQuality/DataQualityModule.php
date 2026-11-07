<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataQuality;

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Bing\BingService;
use SeoControlCenter\Modules\Opportunities\SignalCollector;

/**
 * Data quality inspector (the research workflows plan, phase B): one
 * project page and the MCP `data_quality` tool over the same service.
 * Reads stored data only; no imports, provider calls or migrations.
 */
final class DataQualityModule implements Module
{
    public function name(): string
    {
        return 'data_quality';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(DataQualityService::class, static fn(Container $c): DataQualityService => new DataQualityService(
            $c->make(SignalCollector::class),
            $c->make(SearchPerformanceReader::class),
            $c->make(ProjectPropertyRepository::class),
            $c->make(BingService::class),
            $c->make(Ga4Repository::class),
            $c->make(Clock::class),
            $c->has(\SeoControlCenter\Modules\Analytics\BusinessMeasurementService::class) ? static fn(\SeoControlCenter\Domain\Projects\Project $project): array => $c->make(\SeoControlCenter\Modules\Analytics\BusinessMeasurementService::class)->overview($project)['assessment'] : null,
        ));
        $container->singleton(DataQualityController::class, static fn(Container $c): DataQualityController => new DataQualityController(
            $c->make(ProjectService::class),
            $c->make(DataQualityService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/projects/{project:' . Uuid::ROUTE_PATTERN . '}/data-quality', DataQualityController::class, 'index', 'data_quality.index');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
