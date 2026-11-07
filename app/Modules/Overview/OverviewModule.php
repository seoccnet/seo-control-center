<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Overview;

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Modules\Bing\BingService;
use SeoControlCenter\Modules\Competitors\CompetitorService;
use SeoControlCenter\Modules\Opportunities\OpportunityService;
use SeoControlCenter\Modules\Overview\Controllers\ProjectOverviewController;
use SeoControlCenter\Modules\Rankings\RankingService;
use SeoControlCenter\Modules\SearchConsole\SearchConsoleService;

/**
 * The project overview page (/projects/{project}). Registered after the
 * modules whose services it combines.
 */
final class OverviewModule implements Module
{
    public function name(): string
    {
        return 'overview';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(OverviewEvidence::class, static fn(Container $c): OverviewEvidence => new OverviewEvidence(
            $c->make(SearchPerformanceReader::class),
            $c->make(\SeoControlCenter\Modules\Analytics\Ga4Repository::class),
            $c->make(\SeoControlCenter\Domain\Rankings\RankingRepository::class),
            $c->make(\SeoControlCenter\Domain\Keywords\KeywordRepository::class),
            $c->make(\SeoControlCenter\Modules\Research\ResearchRepository::class),
            $c->make(\SeoControlCenter\Modules\Pages\PageRepository::class),
            $c->make(\SeoControlCenter\Modules\AiVisibility\AiOverviewRepository::class),
            $c->make(\SeoControlCenter\Modules\Research\AiMentionHistory::class),
            $c->make(\SeoControlCenter\Modules\Research\BrandMentionRepository::class),
            $c->make(\SeoControlCenter\Modules\Backlinks\BacklinkHistory::class),
            $c->make(\SeoControlCenter\Modules\SiteAudit\SiteAuditRepository::class),
            $c->make(\SeoControlCenter\Modules\WebVitals\WebVitalsRepository::class),
            $c->make(\SeoControlCenter\Modules\Indexing\IndexingService::class),
            $c->make(\SeoControlCenter\Modules\Annotations\AnnotationRepository::class),
            $c->make(\SeoControlCenter\Modules\Annotations\ChangeImpactService::class),
            $c->make(\SeoControlCenter\Modules\Local\LocalRepository::class),
            $c->make(\SeoControlCenter\Core\Support\Clock::class),
        ));
        $container->singleton(ProjectOverview::class, static fn(Container $c): ProjectOverview => new ProjectOverview(
            $c->make(ProjectService::class),
            $c->make(SearchConsoleService::class),
            $c->make(SearchPerformanceReader::class),
            $c->make(SearchPerformanceStore::class),
            $c->make(RankingService::class),
            $c->make(CompetitorService::class),
            $c->make(OpportunityService::class),
            $c->make(BingService::class),
            $c->make(OverviewEvidence::class),
            $c->make(\SeoControlCenter\Core\Support\Clock::class),
            $c->make(\SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras::class),
        ));
        $container->singleton(ProjectOverviewController::class, static fn(Container $c): ProjectOverviewController => new ProjectOverviewController(
            $c->make(ProjectService::class),
            $c->make(ProjectOverview::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(\SeoControlCenter\Modules\Annotations\AnnotationService::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/projects/{project:' . Uuid::ROUTE_PATTERN . '}', ProjectOverviewController::class, 'show', 'projects.show');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
