<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Routing;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use InvalidArgumentException;
use SeoControlCenter\Core\Http\Exception\MethodNotAllowedHttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;

use function FastRoute\simpleDispatcher;

/**
 * Router adapter backed by nikic/fast-route.
 */
final class FastRouteRouter implements Router
{
    private ?Dispatcher $dispatcher = null;

    public function __construct(private readonly RouteCollection $routes) {}

    public function match(string $method, string $path): RouteMatch
    {
        $result = $this->dispatcher()->dispatch(strtoupper($method), $this->normalizePath($path));

        switch ($result[0]) {
            case Dispatcher::FOUND:
                /** @var Route $route */
                $route = $result[1];
                /** @var array<string, string> $params */
                $params = $result[2];

                return new RouteMatch($route, array_map('rawurldecode', $params));
            case Dispatcher::METHOD_NOT_ALLOWED:
                /** @var list<string> $allowed */
                $allowed = $result[1];

                throw new MethodNotAllowedHttpException($allowed);
            default:
                throw new NotFoundHttpException();
        }
    }

    public function path(string $name, array $parameters = []): string
    {
        $route = $this->routes->named($name);
        if ($route === null) {
            throw new InvalidArgumentException(sprintf('Unknown route "%s".', $name));
        }

        $path = preg_replace_callback(
            // {name} or {name:regex}; the regex may itself contain {n,m} quantifiers.
            '/\{(\w+)(?::(?:[^{}]++|\{[^{}]*\})+)?\}/',
            static function (array $m) use ($parameters, $name): string {
                if (!array_key_exists($m[1], $parameters)) {
                    throw new InvalidArgumentException(sprintf('Missing parameter "%s" for route "%s".', $m[1], $name));
                }

                return rawurlencode((string) $parameters[$m[1]]);
            },
            $route->path,
        );

        if ($path === null || str_contains($path, '[')) {
            throw new InvalidArgumentException(sprintf('Route "%s" uses optional segments, which path() does not support.', $name));
        }

        return $path;
    }

    private function dispatcher(): Dispatcher
    {
        return $this->dispatcher ??= simpleDispatcher(function (RouteCollector $collector): void {
            foreach ($this->routes->all() as $route) {
                $collector->addRoute($route->methods, $route->path, $route);
            }
        });
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path !== '/' ? rtrim($path, '/') : $path;
    }
}
