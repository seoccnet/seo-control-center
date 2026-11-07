<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Routing;

/**
 * A route definition. The handler is a controller class name and method,
 * resolved through the container at dispatch time.
 *
 * Routes require an authenticated user unless marked public.
 */
final readonly class Route
{
    /**
     * @param list<string> $methods
     * @param class-string $controller
     */
    public function __construct(
        public array $methods,
        public string $path,
        public string $controller,
        public string $action,
        public ?string $name = null,
        public bool $public = false,
    ) {}
}
