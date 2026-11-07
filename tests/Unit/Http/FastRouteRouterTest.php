<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Exception\MethodNotAllowedHttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Http\Routing\FastRouteRouter;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use stdClass;

final class FastRouteRouterTest extends TestCase
{
    private FastRouteRouter $router;

    protected function setUp(): void
    {
        $routes = new RouteCollection();
        $routes->get('/', stdClass::class, 'home', 'home');
        $routes->get('/projects/{project:[0-9a-f-]{36}}', stdClass::class, 'show', 'projects.show');
        $routes->post('/projects', stdClass::class, 'store');
        $this->router = new FastRouteRouter($routes);
    }

    public function testMatchesRouteWithParameters(): void
    {
        $uuid = '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f70';
        $match = $this->router->match('GET', '/projects/' . $uuid);

        self::assertSame('show', $match->route->action);
        self::assertSame(['project' => $uuid], $match->parameters);
    }

    public function testTrailingSlashIsNormalised(): void
    {
        self::assertSame('store', $this->router->match('POST', '/projects/')->route->action);
    }

    public function testUnknownPathIs404(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->router->match('GET', '/nope');
    }

    public function testWrongMethodIs405WithAllowedMethods(): void
    {
        try {
            $this->router->match('DELETE', '/projects');
            self::fail('Expected 405');
        } catch (MethodNotAllowedHttpException $e) {
            self::assertSame(['POST'], $e->allowedMethods());
            self::assertSame(405, $e->statusCode());
        }
    }

    public function testParameterConstraintIsEnforced(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->router->match('GET', '/projects/1');
    }

    public function testBuildsPathsForNamedRoutes(): void
    {
        self::assertSame('/', $this->router->path('home'));
        self::assertSame('/projects/abc', $this->router->path('projects.show', ['project' => 'abc']));
    }

    public function testPathEncodesParameters(): void
    {
        self::assertSame('/projects/a%2Fb', $this->router->path('projects.show', ['project' => 'a/b']));
    }
}
