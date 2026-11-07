<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Accounts\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Session\SessionStore;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\UserAdministration;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * System → Users. Deactivating a user also ends all of their sessions.
 */
final readonly class UserAdminController
{
    private const string PATH = '/system/users';

    public function __construct(
        private UserAdministration $administration,
        private SessionStore $sessions,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = WebContext::user($request);

        return $this->responses->html($this->views->render('system/users', [
            'users' => $this->administration->overview($actor),
            'actorId' => $actor->id,
            'canSendResetLinks' => $this->administration->canSendResetLinks(),
        ], $request));
    }

    public function deactivate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, function (ClientInfo $client) use ($request): string {
            $user = $this->administration->deactivate(WebContext::user($request), WebContext::routeParam($request, 'user'), $client);
            $count = $this->sessions->destroyForUser($user->id);

            return sprintf('%s was deactivated%s.', $user->name, $count > 0 ? sprintf(' and signed out of %d session(s)', $count) : '');
        });
    }

    public function activate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, fn(ClientInfo $client): string => sprintf(
            '%s can sign in again.',
            $this->administration->activate(WebContext::user($request), WebContext::routeParam($request, 'user'), $client)->name,
        ));
    }

    public function resetMfa(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, fn(ClientInfo $client): string => sprintf(
            'Two-factor sign-in of %s was reset. They sign in with their password and can set it up again.',
            $this->administration->resetMfa(WebContext::user($request), WebContext::routeParam($request, 'user'), $client)->name,
        ));
    }

    public function sendReset(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, function (ClientInfo $client) use ($request): string {
            $result = $this->administration->sendPasswordReset(WebContext::user($request), WebContext::routeParam($request, 'user'), $client);
            if (!$result['sent']) {
                throw new ValidationException(['user' => sprintf('The e-mail to %s could not be sent. Check System → E-mail.', $result['user']->email)]);
            }

            return sprintf('A password-reset link was sent to %s.', $result['user']->email);
        });
    }

    public function grantAdmin(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, fn(ClientInfo $client): string => sprintf(
            '%s is now an installation administrator.',
            $this->administration->setAdmin(WebContext::user($request), WebContext::routeParam($request, 'user'), true, $client)->name,
        ));
    }

    public function revokeAdmin(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, fn(ClientInfo $client): string => sprintf(
            '%s is no longer an installation administrator.',
            $this->administration->setAdmin(WebContext::user($request), WebContext::routeParam($request, 'user'), false, $client)->name,
        ));
    }

    /**
     * @param \Closure(ClientInfo): string $action returns the success message
     */
    private function act(ServerRequestInterface $request, \Closure $action): ResponseInterface
    {
        $session = WebContext::session($request);
        try {
            $session->flash('status', $action(ClientInfo::fromRequest($request)));
        } catch (ValidationException $e) {
            $session->flash('error', implode(' ', $e->errors()));
        }

        return $this->responses->redirect(self::PATH);
    }
}
