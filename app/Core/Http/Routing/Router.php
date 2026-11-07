<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Routing;

use SeoControlCenter\Core\Http\Exception\MethodNotAllowedHttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;

/**
 * Application-owned routing contract (ADR-016). The implementation can be
 * swapped without touching controllers or modules.
 */
interface Router
{
    /**
     * @throws NotFoundHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function match(string $method, string $path): RouteMatch;

    /**
     * Builds a path for a named route.
     *
     * @param array<string, string|int> $parameters
     */
    public function path(string $name, array $parameters = []): string;
}
