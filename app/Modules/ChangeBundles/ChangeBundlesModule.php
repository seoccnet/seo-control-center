<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ChangeBundles;

use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Annotations\AnnotationService;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;

/** Change bundles (ADR-042, 0.40.0): record, verify and guard; SEOcc never edits the live site. */
final class ChangeBundlesModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'change_bundles';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(ChangeBundleRepository::class, static fn(Container $c): ChangeBundleRepository => new Persistence\PdoChangeBundleRepository($c->make(Connection::class)));
        $container->singleton(LivePageReader::class, static fn(Container $c): LivePageReader => new CrawlerLivePageReader($c->make(SiteCrawler::class)));
        $container->singleton(BundleCheckScheduler::class, static fn(Container $c): BundleCheckScheduler => new QueuedBundleChecks($c->make(Queue::class)));
        $container->singleton(BundleDrafts::class, static fn(Container $c): BundleDrafts => new BundleDrafts($c->has(\SeoControlCenter\Modules\Opportunities\OpportunityService::class) ? $c->make(\SeoControlCenter\Modules\Opportunities\OpportunityService::class) : null));
        $container->singleton(ChangeBundleService::class, static fn(Container $c): ChangeBundleService => new ChangeBundleService($c->make(ChangeBundleRepository::class), $c->make(BundleCheckScheduler::class), $c->make(LivePageReader::class), $c->make(ProjectRepository::class), $c->make(AnnotationService::class), $c->make(Authorizer::class), $c->make(AuditLogger::class), $c->make(Clock::class), $c->make(FeatureManager::class), $c->make(BundleDrafts::class), $c->has(\SeoControlCenter\Modules\Annotations\MeasurementPlanService::class) ? $c->make(\SeoControlCenter\Modules\Annotations\MeasurementPlanService::class) : null));
        $container->singleton(Controllers\ChangeBundlesController::class, static fn(Container $c): Controllers\ChangeBundlesController => new Controllers\ChangeBundlesController($c->make(ProjectService::class), $c->make(ChangeBundleService::class), $c->make(ResponseFactory::class), $c->make(ViewRenderer::class), $c->make(BundleDrafts::class)));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/change-bundles';
        $id = $base . '/{bundle:' . Uuid::ROUTE_PATTERN . '}';
        $routes->get($base, Controllers\ChangeBundlesController::class, 'index', 'change_bundles.index');
        $routes->post($base, Controllers\ChangeBundlesController::class, 'store', 'change_bundles.store');
        $routes->get($id, Controllers\ChangeBundlesController::class, 'show', 'change_bundles.show');
        $routes->post($id, Controllers\ChangeBundlesController::class, 'update', 'change_bundles.update');
        $routes->post($id . '/check', Controllers\ChangeBundlesController::class, 'check', 'change_bundles.check');
        $routes->post($id . '/approve', Controllers\ChangeBundlesController::class, 'approve', 'change_bundles.approve');
        $routes->post($id . '/deploy', Controllers\ChangeBundlesController::class, 'deploy', 'change_bundles.deploy');
        $routes->post($id . '/rollback', Controllers\ChangeBundlesController::class, 'rollback', 'change_bundles.rollback');
        $routes->post($id . '/delete', Controllers\ChangeBundlesController::class, 'delete', 'change_bundles.delete');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register('change_bundles.check', static fn(): Jobs\BundleCheckJob => new Jobs\BundleCheckJob($container->make(ChangeBundleService::class)));
        $jobs->requireFeature('change_bundles.check', Feature::SiteAudit);
    }

    public function registerSchedule(Scheduler $scheduler): void {}

    public function migrationPaths(): array
    {
        return [];
    }
}
