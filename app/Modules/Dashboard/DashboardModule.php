<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Dashboard;

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Dashboard\Controllers\DashboardController;
use SeoControlCenter\Modules\Overview\ProjectOverview;

final class DashboardModule implements Module
{
    public function name(): string
    {
        return 'dashboard';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(DashboardController::class, static fn(Container $c): DashboardController => new DashboardController(
            $c->make(ProjectService::class),
            $c->make(ProjectOverview::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/', DashboardController::class, 'index', 'dashboard');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
