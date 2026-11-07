<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Clusters;

use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Clusters\ClusterRefresher;
use SeoControlCenter\Domain\Clusters\ClusterRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Clusters\Persistence\PdoClusterRepository;
use SeoControlCenter\Modules\Pages\PageRepository;

/**
 * Keyword clusters with page ownership and query-to-page overlap (ADR-037,
 * C2): Keywords → Topics & owners, refreshed by each opportunity analysis.
 */
final class ClustersModule implements Module
{
    public function name(): string
    {
        return 'clusters';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(ClusterRepository::class, static fn(Container $c): ClusterRepository => new PdoClusterRepository($c->make(Connection::class)));
        $container->singleton(ClusterService::class, static fn(Container $c): ClusterService => new ClusterService(
            $c->make(ClusterRepository::class),
            $c->make(PageRepository::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->has(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class) ? $c->make(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class) : null,
        ));
        $container->singleton(ClusterRefresher::class, static fn(Container $c): ClusterRefresher => $c->make(ClusterService::class));
        $container->singleton(ClustersController::class, static fn(Container $c): ClustersController => new ClustersController(
            $c->make(ProjectService::class),
            $c->make(ClusterService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/keywords/topics';
        $routes->get($base, ClustersController::class, 'index', 'clusters.index');
        $routes->post($base . '/{cluster:' . Uuid::ROUTE_PATTERN . '}/owner', ClustersController::class, 'owner', 'clusters.owner');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
