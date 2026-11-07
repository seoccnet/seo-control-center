<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Routing;

final readonly class RouteMatch
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public Route $route,
        public array $parameters,
    ) {}
}
