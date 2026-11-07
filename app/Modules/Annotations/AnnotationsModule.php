<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations;

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
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Annotations\Controllers\AnnotationsController;
use SeoControlCenter\Modules\Annotations\Persistence\PdoAnnotationRepository;

final class AnnotationsModule implements Module
{
    public function name(): string
    {
        return 'annotations';
    }
    public function registerServices(Container $container): void
    {
        $container->singleton(AnnotationRepository::class, static fn(Container $c): AnnotationRepository => new PdoAnnotationRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(AnnotationObservers::class, static fn(Container $c): AnnotationObservers => new AnnotationObservers($c->make(\Psr\Log\LoggerInterface::class)));
        $container->singleton(AnnotationService::class, static fn(Container $c): AnnotationService => new AnnotationService($c->make(AnnotationRepository::class), $c->make(Authorizer::class), $c->make(AuditLogger::class), $c->make(Clock::class), $c->make(AnnotationObservers::class)));
        $container->singleton(ChangeImpactService::class, static fn(Container $c): ChangeImpactService => new ChangeImpactService($c->make(AnnotationService::class), $c->make(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader::class), $c->make(\SeoControlCenter\Modules\Pages\PageRepository::class), $c->make(\SeoControlCenter\Modules\Analytics\Ga4Repository::class), $c->make(Clock::class)));
        $container->singleton(MeasurementPlanRepository::class, static fn(Container $c): MeasurementPlanRepository => new Persistence\PdoMeasurementPlanRepository($c->make(Connection::class)));
        $container->singleton(MeasurementPlanService::class, static fn(Container $c): MeasurementPlanService => new MeasurementPlanService($c->make(AnnotationService::class), $c->make(MeasurementPlanRepository::class), $c->make(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader::class), $c->make(\SeoControlCenter\Modules\Pages\PageRepository::class), $c->make(\SeoControlCenter\Modules\Analytics\Ga4Repository::class), $c->make(Authorizer::class), $c->make(AuditLogger::class), $c->make(Clock::class)));
        $container->singleton(ResultsLedgerService::class, static fn(Container $c): ResultsLedgerService => new ResultsLedgerService(
            $c->make(MeasurementPlanService::class),
            $c->make(MeasurementPlanRepository::class),
            $c->make(AnnotationRepository::class),
            $c->make(Authorizer::class),
            $c->make(Clock::class),
            // The playbook a change was deployed from: its change bundle, started on a finding (0.41.0).
            $c->has(\SeoControlCenter\Modules\ChangeBundles\ChangeBundleRepository::class) ? static function (\SeoControlCenter\Domain\Projects\Project $project) use ($c): array {
                $sources = [];
                foreach ($c->make(\SeoControlCenter\Modules\ChangeBundles\ChangeBundleRepository::class)->list($project, 500) as $bundle) {
                    $change = $bundle['data']['annotation_id'] ?? null;
                    $source = $bundle['data']['source'] ?? null;
                    if (is_string($change) && is_array($source) && is_string($source['playbook'] ?? null)) {
                        $sources[$change] = $source['playbook'];
                    }
                }

                return $sources;
            } : null,
        ));
        $container->singleton(AnnotationsController::class, static fn(Container $c): AnnotationsController => new AnnotationsController($c->make(ProjectService::class), $c->make(AnnotationService::class), $c->make(ResponseFactory::class), $c->make(ViewRenderer::class), $c->make(Clock::class), $c->make(ChangeImpactService::class), $c->make(MeasurementPlanService::class), $c->make(ResultsLedgerService::class)));
    }
    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/annotations';
        $id = '{annotation:' . Uuid::ROUTE_PATTERN . '}';
        $routes->get($base, AnnotationsController::class, 'index', 'annotations.index');
        $routes->get($base . '/results', AnnotationsController::class, 'results', 'annotations.results');
        $routes->post($base . '/results/measure-past', AnnotationsController::class, 'measurePast', 'annotations.results.measure_past');
        $routes->post($base, AnnotationsController::class, 'store', 'annotations.store');
        $routes->get($base . '/' . $id . '/edit', AnnotationsController::class, 'edit', 'annotations.edit');
        $routes->get($base . '/' . $id . '/impact', AnnotationsController::class, 'impact', 'annotations.impact');
        $routes->post($base . '/' . $id, AnnotationsController::class, 'update', 'annotations.update');
        $routes->post($base . '/' . $id . '/delete', AnnotationsController::class, 'remove', 'annotations.remove');
        $routes->post($base . '/' . $id . '/plan', AnnotationsController::class, 'savePlan', 'annotations.plan.save');
        $routes->post($base . '/' . $id . '/plan/freeze', AnnotationsController::class, 'freezePlan', 'annotations.plan.freeze');
    }
    public function migrationPaths(): array
    {
        return [];
    }
}
