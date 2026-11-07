<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Projects;

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
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Projects\Controllers\ProjectsController;
use SeoControlCenter\Modules\Projects\Persistence\PdoProjectRepository;

final class ProjectsModule implements Module
{
    public function name(): string
    {
        return 'projects';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(ProjectRepository::class, static fn(Container $c): ProjectRepository => new PdoProjectRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(ProjectService::class, static fn(Container $c): ProjectService => new ProjectService(
            $c->make(ProjectRepository::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
        ));
        $container->singleton(ProjectsController::class, static fn(Container $c): ProjectsController => new ProjectsController(
            $c->make(ProjectService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $id = '{project:' . Uuid::ROUTE_PATTERN . '}';

        $routes->get('/projects', ProjectsController::class, 'index', 'projects.index');
        $routes->get('/projects/new', ProjectsController::class, 'create', 'projects.create');
        $routes->post('/projects', ProjectsController::class, 'store', 'projects.store');
        $routes->get('/projects/' . $id . '/edit', ProjectsController::class, 'edit', 'projects.edit');
        $routes->post('/projects/' . $id, ProjectsController::class, 'update', 'projects.update');
        $routes->post('/projects/' . $id . '/delete', ProjectsController::class, 'destroy', 'projects.destroy');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
