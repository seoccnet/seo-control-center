<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentInventory;

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
use SeoControlCenter\Modules\ContentInventory\Controllers\ContentInventoryController;

/**
 * Research → Content inventory (ADR-042, 0.33.0): every page, draft and
 * planned article in one list, checked before new content is recommended.
 * Nothing here calls a provider.
 */
final class ContentInventoryModule implements Module
{
    public function name(): string
    {
        return 'content_inventory';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(ContentItemRepository::class, static fn(Container $c): ContentItemRepository => new Persistence\PdoContentItemRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(InventorySources::class, static fn(Container $c): InventorySources => new Persistence\PdoInventorySources($c->make(Connection::class)));
        $container->singleton(ContentInventoryService::class, static fn(Container $c): ContentInventoryService => new ContentInventoryService(
            $c->make(ContentItemRepository::class),
            $c->make(InventorySources::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
        ));
        $container->singleton(OverlapReviewRepository::class, static fn(Container $c): OverlapReviewRepository => new Persistence\PdoOverlapReviewRepository($c->make(Connection::class), $c->make(Clock::class)));
        $clusters = static fn(Container $c, \SeoControlCenter\Domain\Projects\Project $project): array => $c->has(\SeoControlCenter\Domain\Clusters\ClusterRepository::class) ? $c->make(\SeoControlCenter\Domain\Clusters\ClusterRepository::class)->forProject($project, false) : [];
        $container->singleton(OverlapService::class, static fn(Container $c): OverlapService => new OverlapService(
            $c->make(OverlapReviewRepository::class),
            $c->make(ContentInventoryService::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
            // Article ideas (ADR-041) when that module is enabled.
            $c->has(\SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRepository::class) ? static fn(\SeoControlCenter\Domain\Projects\Project $project): array => array_map(
                static fn(\SeoControlCenter\Domain\ArticleIdeas\ArticleIdea $i): array => ['key' => $i->key, 'main_keyword' => $i->mainKeyword, 'kind' => $i->kind],
                $c->make(\SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRepository::class)->ideas($project, 100),
            ) : null,
            // Searches shown by several pages, as Topics & owners classified them.
            static function (\SeoControlCenter\Domain\Projects\Project $project) use ($c, $clusters): array {
                $out = [];
                foreach ($clusters($c, $project) as $cluster) {
                    foreach ($cluster->overlap as $overlap) {
                        if (is_array($overlap) && is_string($overlap['query'] ?? null) && is_array($overlap['pages'] ?? null)) {
                            $out[] = ['query' => $overlap['query'], 'kind' => (string) ($overlap['kind'] ?? 'split'), 'harmful' => is_bool($overlap['harmful'] ?? null) ? $overlap['harmful'] : null, 'note' => (string) ($overlap['note'] ?? ''), 'pages' => array_values(array_map(static fn(array $p): array => ['url' => (string) $p['url'], 'share' => (float) ($p['share'] ?? 0), 'position' => (float) ($p['position'] ?? 0)], array_filter($overlap['pages'], static fn(mixed $p): bool => is_array($p) && is_string($p['url'] ?? null))))];
                        }
                    }
                }

                return $out;
            },
            // The topics each page owns.
            static function (\SeoControlCenter\Domain\Projects\Project $project) use ($c, $clusters): array {
                $owners = [];
                foreach ($clusters($c, $project) as $cluster) {
                    $url = $cluster->manualOwnerUrl ?? $cluster->ownerUrl;
                    if ($url !== null) {
                        $owners[$url][] = $cluster->label;
                    }
                }

                return $owners;
            },
        ));
        $container->singleton(Controllers\OverlapController::class, static fn(Container $c): Controllers\OverlapController => new Controllers\OverlapController($c->make(ProjectService::class), $c->make(OverlapService::class), $c->make(ResponseFactory::class), $c->make(ViewRenderer::class)));
        $container->singleton(ContentInventoryController::class, static fn(Container $c): ContentInventoryController => new ContentInventoryController(
            $c->make(ProjectService::class),
            $c->make(ContentInventoryService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/inventory';
        $item = $base . '/items/{item:' . Uuid::ROUTE_PATTERN . '}';
        $routes->get($base, ContentInventoryController::class, 'index', 'projects.inventory');
        $routes->get($base . '/overlap', Controllers\OverlapController::class, 'index', 'projects.inventory.overlap');
        $routes->post($base . '/overlap/{candidate:[a-f0-9]{64}}', Controllers\OverlapController::class, 'decide', 'projects.inventory.overlap.decide');
        $routes->post($base . '/items', ContentInventoryController::class, 'add', 'projects.inventory.add');
        $routes->post($base . '/import', ContentInventoryController::class, 'import', 'projects.inventory.import');
        $routes->post($item, ContentInventoryController::class, 'update', 'projects.inventory.update');
        $routes->post($item . '/link', ContentInventoryController::class, 'link', 'projects.inventory.link');
        $routes->post($item . '/delete', ContentInventoryController::class, 'remove', 'projects.inventory.remove');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
