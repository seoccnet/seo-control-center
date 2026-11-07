<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Quests;

use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Quests\ShowMe;

/**
 * Resolves a "Show me" target (named route + selector) to a URL. Project
 * routes take the selected project; routes needing any other parameter are
 * not valid targets.
 */
final readonly class ShowMeTargets
{
    private const string PROJECT = '{project:' . Uuid::ROUTE_PATTERN . '}';

    public function __construct(private RouteCollection $routes) {}

    public function url(ShowMe $target, ?Project $project): ?string
    {
        $route = $this->routes->named($target->route);
        if ($route === null) {
            return null;
        }
        $path = $route->path;
        if (str_contains($path, self::PROJECT)) {
            if ($project === null) {
                return null;
            }
            $path = str_replace(self::PROJECT, $project->publicId, $path);
        }

        return str_contains($path, '{') ? null : $path;
    }

    /** Whether the target names an existing route without parameters other than the project. */
    public function valid(ShowMe $target): bool
    {
        $route = $this->routes->named($target->route);

        return $route !== null && !str_contains(str_replace(self::PROJECT, 'x', $route->path), '{');
    }
}
