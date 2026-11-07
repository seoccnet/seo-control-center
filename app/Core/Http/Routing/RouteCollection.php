<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Routing;

use InvalidArgumentException;

/**
 * Collects route definitions from modules and route files.
 */
final class RouteCollection
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    /**
     * @param class-string $controller
     */
    public function get(string $path, string $controller, string $action, ?string $name = null, bool $public = false): Route
    {
        return $this->add(['GET', 'HEAD'], $path, $controller, $action, $name, $public);
    }

    /**
     * @param class-string $controller
     */
    public function post(string $path, string $controller, string $action, ?string $name = null, bool $public = false): Route
    {
        return $this->add(['POST'], $path, $controller, $action, $name, $public);
    }

    /**
     * @param list<string> $methods
     * @param class-string $controller
     */
    public function add(array $methods, string $path, string $controller, string $action, ?string $name = null, bool $public = false): Route
    {
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException(sprintf('Route path "%s" must start with "/".', $path));
        }
        $methods = array_values(array_unique(array_map('strtoupper', $methods)));
        $route = new Route($methods, $path, $controller, $action, $name, $public);

        if ($name !== null) {
            if (isset($this->named[$name])) {
                throw new InvalidArgumentException(sprintf('Route name "%s" is already used.', $name));
            }
            $this->named[$name] = $route;
        }
        $this->routes[] = $route;

        return $route;
    }

    /**
     * @return list<Route>
     */
    public function all(): array
    {
        return $this->routes;
    }

    public function named(string $name): ?Route
    {
        return $this->named[$name] ?? null;
    }
}
