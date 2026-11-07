<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Branding;

use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Branding\BrandingRepository;
use SeoControlCenter\Core\Branding\CurrentBranding;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Modules\Branding\Controllers\BrandingController;
use SeoControlCenter\Modules\Branding\Persistence\PdoBrandingRepository;

/**
 * White label (roadmap 1.1): the installation's product name, logo, accent
 * colour and report footer, used by the layout, the report page and the
 * report e-mails.
 */
final class BrandingModule implements Module
{
    public function name(): string
    {
        return 'branding';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(BrandingRepository::class, static fn(Container $c): BrandingRepository => new PdoBrandingRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(BrandingService::class, static fn(Container $c): BrandingService => new BrandingService(
            $c->make(BrandingRepository::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
            $c->make(Paths::class)->storage('branding'),
            $c->make(CurrentBranding::class),
        ));
        $container->singleton(BrandingController::class, static fn(Container $c): BrandingController => new BrandingController(
            $c->make(BrandingService::class),
            $c->make(Authorizer::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(Paths::class)->storage('tmp'),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/system/branding', BrandingController::class, 'show', 'system.branding');
        $routes->post('/system/branding', BrandingController::class, 'save');
        $routes->post('/system/branding/logo', BrandingController::class, 'uploadLogo');
        $routes->post('/system/branding/logo/remove', BrandingController::class, 'removeLogo');
        $routes->get('/branding/logo', BrandingController::class, 'logo', 'branding.logo', public: true);
        $routes->get('/branding.css', BrandingController::class, 'stylesheet', 'branding.css', public: true);
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
