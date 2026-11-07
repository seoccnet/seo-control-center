<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Shared\Http;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Session\Session;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Workspaces\CurrentWorkspace;

/**
 * Typed access to the per-request context set by middleware.
 */
final class WebContext
{
    private function __construct() {}

    public static function user(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(RequestAttribute::USER);
        if (!$user instanceof User) {
            throw new RuntimeException('No authenticated user on this request.');
        }

        return $user;
    }

    public static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(RequestAttribute::SESSION);
        if (!$session instanceof Session) {
            throw new RuntimeException('No session on this request.');
        }

        return $session;
    }

    public static function workspace(ServerRequestInterface $request): CurrentWorkspace
    {
        $workspace = $request->getAttribute(RequestAttribute::WORKSPACE);
        if (!$workspace instanceof CurrentWorkspace) {
            throw new HttpException(403, 'You are not a member of any workspace yet.');
        }

        return $workspace;
    }

    public static function routeParam(ServerRequestInterface $request, string $name): string
    {
        $params = $request->getAttribute(RequestAttribute::ROUTE_PARAMS);
        $value = is_array($params) ? ($params[$name] ?? null) : null;
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Missing route parameter "%s".', $name));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
