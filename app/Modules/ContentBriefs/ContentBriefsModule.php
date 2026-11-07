<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentBriefs;

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
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Modules\ContentBriefs\Controllers\BriefsController;
use SeoControlCenter\Modules\ContentBriefs\Persistence\PdoBriefRepository;
use SeoControlCenter\Modules\Pages\PageService;

final class ContentBriefsModule implements Module
{
    public function name(): string
    {
        return 'content-briefs';
    }
    public function registerServices(Container $container): void
    {
        $container->singleton(BriefRepository::class, static fn(Container $c): BriefRepository => new PdoBriefRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(BriefBuilder::class, static fn(Container $c): BriefBuilder => new BriefBuilder($c->make(PageService::class), $c->make(KeywordRepository::class), $c->make(RankingRepository::class), $c->make(OpportunityRepository::class), $c->make(Clock::class)));
        $container->singleton(BriefService::class, static fn(Container $c): BriefService => new BriefService(
            $c->make(BriefRepository::class),
            $c->make(BriefBuilder::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
            $c->make(\SeoControlCenter\Core\Support\Clock::class),
            // A brief saved as published tracks its article idea (ADR-041 G3), when that module is enabled.
            $c->has(\SeoControlCenter\Modules\ArticleIdeas\ArticleTrackingService::class)
                ? static fn(\SeoControlCenter\Domain\Projects\Project $project, ContentBrief $brief) => $c->make(\SeoControlCenter\Modules\ArticleIdeas\ArticleTrackingService::class)->fromBrief($project, $brief->input->keyword, $brief->input->url, $brief->input->status, $brief->publishedAt)
                : null,
        ));
        $container->singleton(ClaimRepository::class, static fn(Container $c): ClaimRepository => new Persistence\PdoClaimRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(ClaimService::class, static fn(Container $c): ClaimService => new ClaimService(
            $c->make(ClaimRepository::class),
            $c->make(BriefService::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            // A competitor's statement is not independent validation (ADR-042).
            $c->has(\SeoControlCenter\Domain\Competitors\CompetitorRepository::class) ? static fn(int $projectId): array => array_values(array_map(static fn(\SeoControlCenter\Domain\Competitors\Competitor $x): string => $x->domain, $c->make(\SeoControlCenter\Domain\Competitors\CompetitorRepository::class)->forProject($projectId))) : null,
        ));
        $container->singleton(Controllers\ClaimsController::class, static fn(Container $c): Controllers\ClaimsController => new Controllers\ClaimsController($c->make(ProjectService::class), $c->make(ClaimService::class), $c->make(ResponseFactory::class)));
        $container->singleton(BriefsController::class, static fn(Container $c): BriefsController => new BriefsController(
            $c->make(ProjectService::class),
            $c->make(BriefService::class),
            $c->make(BriefBuilder::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            // Prefill from an article idea (ADR-041, G1 part 2) when that module is enabled.
            $c->has(\SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaService::class) ? $c->make(\SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaService::class)->briefSeed(...) : null,
            // Pages and drafts that already cover the keyword (ADR-042).
            $c->has(\SeoControlCenter\Modules\ContentInventory\ContentInventoryService::class) ? static fn(\SeoControlCenter\Domain\Projects\Project $project, array $topics): array => $c->make(\SeoControlCenter\Modules\ContentInventory\ContentInventoryService::class)->covering($project, array_values(array_map(strval(...), $topics))) : null,
            $c->make(ClaimService::class),
        ));
    }
    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/content-briefs';
        $id = '{brief:' . Uuid::ROUTE_PATTERN . '}';
        $routes->get($base, BriefsController::class, 'index', 'briefs.index');
        $routes->get($base . '/new', BriefsController::class, 'create', 'briefs.create');
        $routes->post($base, BriefsController::class, 'store', 'briefs.store');
        $routes->get($base . '/' . $id, BriefsController::class, 'show', 'briefs.show');
        $routes->get($base . '/' . $id . '/edit', BriefsController::class, 'edit', 'briefs.edit');
        $routes->post($base . '/' . $id, BriefsController::class, 'update', 'briefs.update');
        $routes->post($base . '/' . $id . '/delete', BriefsController::class, 'remove', 'briefs.remove');
        $routes->get($base . '/' . $id . '/print', BriefsController::class, 'show', 'briefs.print');
        $routes->get($base . '/' . $id . '/export', BriefsController::class, 'export', 'briefs.export');
        $claim = $base . '/' . $id . '/claims/{claim:' . Uuid::ROUTE_PATTERN . '}';
        $routes->post($base . '/' . $id . '/claims', Controllers\ClaimsController::class, 'add', 'briefs.claims.add');
        $routes->post($claim, Controllers\ClaimsController::class, 'update', 'briefs.claims.update');
        $routes->post($claim . '/delete', Controllers\ClaimsController::class, 'remove', 'briefs.claims.remove');
    }
    public function migrationPaths(): array
    {
        return [];
    }
}
