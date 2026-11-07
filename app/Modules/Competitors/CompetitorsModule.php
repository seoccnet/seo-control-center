<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors;

use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Modules\Competitors\Controllers\CompetitorsController;
use SeoControlCenter\Modules\Competitors\Persistence\PdoCompetitorRepository;
use SeoControlCenter\Modules\Rankings\SerpObservers;

/**
 * Competitors (roadmap 0.5): competing domains per project, measured on the
 * SERPs that rank checks fetch, with discovery, visibility and keyword gaps.
 */
final class CompetitorsModule implements Module
{
    public function name(): string
    {
        return 'competitors';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(CompetitorRepository::class, static fn(Container $c): CompetitorRepository => new PdoCompetitorRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        // Rank checks hand every SERP to this observer (see RankingsModule).
        $container->singleton(CompetitorTracker::class, static fn(Container $c): CompetitorTracker => new CompetitorTracker(
            $c->make(CompetitorRepository::class),
        ));
        $container->make(SerpObservers::class)->add(static fn(): SerpObserver => $container->make(CompetitorTracker::class));
        $container->singleton(CompetitorService::class, static fn(Container $c): CompetitorService => new CompetitorService(
            $c->make(CompetitorRepository::class),
            $c->make(RankingRepository::class),
            $c->make(ProjectService::class),
            $c->make(AuditLogger::class),
            $c->make(FeatureManager::class),
        ));
        $container->singleton(CompetitorsController::class, static fn(Container $c): CompetitorsController => new CompetitorsController(
            $c->make(ProjectService::class),
            $c->make(CompetitorService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $path = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/competitors';
        $competitor = '/{competitor:' . Uuid::ROUTE_PATTERN . '}';

        $routes->get($path, CompetitorsController::class, 'index', 'projects.competitors');
        $routes->post($path, CompetitorsController::class, 'add');
        $routes->get($path . $competitor, CompetitorsController::class, 'show', 'projects.competitors.show');
        $routes->post($path . $competitor . '/delete', CompetitorsController::class, 'remove');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
