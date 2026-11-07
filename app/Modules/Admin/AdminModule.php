<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Admin;

use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Modules\Admin\Controllers\AuditLogController;
use SeoControlCenter\Modules\Admin\Persistence\AuditLogReader;
use SeoControlCenter\Modules\Admin\Persistence\PdoAuditLogReader;

final class AdminModule implements Module
{
    public function name(): string
    {
        return 'admin';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(AuditLogReader::class, static fn(Container $c): AuditLogReader => new PdoAuditLogReader($c->make(Connection::class)));
        $container->singleton(AuditLogController::class, static fn(Container $c): AuditLogController => new AuditLogController(
            $c->make(AuditLogReader::class),
            $c->make(Authorizer::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/audit', AuditLogController::class, 'index', 'audit.index');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
