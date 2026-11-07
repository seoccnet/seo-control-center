<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use SeoControlCenter\Core\Http\Exception\UnauthorizedHttpException;
use SeoControlCenter\Core\Http\Routing\Router;
use SeoControlCenter\Core\Session\Session;

/**
 * Final request handler: matches the route and invokes the controller action.
 *
 * Controllers are resolved from the container and called as
 * `$controller->action(ServerRequestInterface $request): ResponseInterface`.
 * Route parameters are available as a request attribute. Non-public routes
 * require an authenticated user (set by the authentication middleware):
 * browsers are redirected to /login, API clients receive 401.
 */
final readonly class RouteDispatcher implements RequestHandlerInterface
{
    public function __construct(
        private Router $router,
        private ContainerInterface $container,
        private ResponseFactory $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());

        $request = $request
            ->withAttribute(RequestAttribute::ROUTE, $match->route)
            ->withAttribute(RequestAttribute::ROUTE_PARAMS, $match->parameters);

        if (!$match->route->public && $request->getAttribute(RequestAttribute::USER) === null) {
            return $this->unauthenticated($request);
        }

        $controller = $this->container->get($match->route->controller);
        $action = $match->route->action;

        if (!is_object($controller) || !method_exists($controller, $action)) {
            throw new RuntimeException(sprintf('Route action %s::%s does not exist.', $match->route->controller, $action));
        }

        $response = $controller->{$action}($request);
        if (!$response instanceof ResponseInterface) {
            throw new RuntimeException(sprintf('Route action %s::%s must return a ResponseInterface.', $match->route->controller, $action));
        }

        return $response;
    }

    private function unauthenticated(ServerRequestInterface $request): ResponseInterface
    {
        $accept = $request->getHeaderLine('Accept');
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/') || (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html'))) {
            throw new UnauthorizedHttpException();
        }

        // Remember the page so that signing in leads back to it (e.g. an AI app's sign-in request).
        $session = $request->getAttribute(RequestAttribute::SESSION);
        if ($session instanceof Session && $request->getMethod() === 'GET') {
            $query = $request->getUri()->getQuery();
            $target = $path . ($query !== '' ? '?' . $query : '');
            if (strlen($target) <= 2000) {
                $session->set(Session::RETURN_TO, $target);
            }
        }

        return $this->responses->redirect('/login')->withHeader('Cache-Control', 'no-store');
    }
}
