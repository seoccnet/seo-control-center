<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Investigations;

use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Comparison\AnalysisRepository;
use SeoControlCenter\Domain\Investigations\InvestigationRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Investigations\Controllers\InvestigationsController;
use SeoControlCenter\Modules\Investigations\Persistence\PdoInvestigationRepository;
use SeoControlCenter\Modules\Research\ResearchOnFile;

/**
 * Research → Investigations (ADR-038, D3): saved research notebooks.
 * Nothing here buys data.
 */
final class InvestigationsModule implements Module
{
    public function name(): string
    {
        return 'investigations';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(InvestigationRepository::class, static fn(Container $c): InvestigationRepository => new PdoInvestigationRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(InvestigationService::class, static fn(Container $c): InvestigationService => new InvestigationService(
            $c->make(InvestigationRepository::class),
            $c->make(ProjectService::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->has(AnalysisRepository::class) ? $c->make(AnalysisRepository::class) : null,
            $c->has(ResearchOnFile::class) ? $c->make(ResearchOnFile::class) : null,
        ));
        $container->singleton(InvestigationsController::class, static fn(Container $c): InvestigationsController => new InvestigationsController(
            $c->make(ProjectService::class),
            $c->make(InvestigationService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/investigations';
        $one = $base . '/{investigation:' . Uuid::ROUTE_PATTERN . '}';
        $routes->get($base, InvestigationsController::class, 'index', 'investigations.index');
        $routes->post($base, InvestigationsController::class, 'store', 'investigations.store');
        $routes->post($base . '/from-analysis', InvestigationsController::class, 'fromAnalysis', 'investigations.from_analysis');
        $routes->get($one, InvestigationsController::class, 'show', 'investigations.show');
        $routes->post($one, InvestigationsController::class, 'update', 'investigations.update');
        $routes->post($one . '/items', InvestigationsController::class, 'addItem', 'investigations.items.add');
        $routes->post($one . '/items/{item:' . Uuid::ROUTE_PATTERN . '}/delete', InvestigationsController::class, 'removeItem', 'investigations.items.remove');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
