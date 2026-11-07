<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Modules;

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Http\Routing\RouteCollection;

/**
 * Module contract (PROJECT_STRUCTURE.md -> Module contract).
 *
 * Slice 1 supports services and routes. Permissions, navigation, jobs,
 * event listeners and health checks are added to this contract as the
 * corresponding core features are built.
 */
interface Module
{
    /**
     * Stable machine name, e.g. "system", "projects".
     */
    public function name(): string;

    public function registerServices(Container $container): void;

    public function registerRoutes(RouteCollection $routes): void;

    /**
     * Absolute paths of directories containing this module's migrations.
     *
     * @return list<string>
     */
    public function migrationPaths(): array;
}
