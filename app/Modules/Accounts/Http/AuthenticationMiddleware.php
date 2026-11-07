<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Accounts\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Logging\LogContext;
use SeoControlCenter\Core\Session\Session;
use SeoControlCenter\Domain\Accounts\UserRepository;

/**
 * Resolves the signed-in user from the session. It does not enforce login;
 * the route dispatcher does that for non-public routes.
 *
 * Disabled or deleted users are signed out immediately.
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UserRepository $users,
        private LogContext $logContext,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(RequestAttribute::SESSION);
        if ($session instanceof Session && $session->userId() !== null) {
            $user = $this->users->findById($session->userId());
            if ($user === null || !$user->active) {
                $session->logout();
            } else {
                $this->logContext->set('user_id', $user->id);
                $request = $request->withAttribute(RequestAttribute::USER, $user);
            }
        }

        return $handler->handle($request);
    }
}
