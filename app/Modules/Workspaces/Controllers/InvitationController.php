<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Workspaces\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\AuthenticationService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\Workspaces\Invitation;
use SeoControlCenter\Domain\Workspaces\MemberService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Public invitation page: the invitee sets a name and password and is signed
 * in, or, with an existing account, joins and then signs in with their own
 * password. Attempts are limited per IP.
 *
 * The pages send "Referrer-Policy: same-origin", not "no-referrer": the token
 * in the URL still never reaches other sites, but under "no-referrer" browsers
 * post the form with "Origin: null", which the CSRF check refuses.
 */
final readonly class InvitationController
{
    private const int MAX_PER_IP = 20;
    private const int WINDOW_SECONDS = 900;

    public function __construct(
        private MemberService $members,
        private AuthenticationService $auth,
        private RateLimiter $rateLimiter,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->throttled($request)) {
            return $this->invalid($request, 429);
        }
        $invitation = $this->members->findInvitation(WebContext::routeParam($request, 'token'));
        if ($invitation === null) {
            $this->hit($request);

            return $this->invalid($request, 404);
        }

        return $this->form($request, $invitation, [], '', 200);
    }

    public function accept(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->throttled($request)) {
            return $this->invalid($request, 429);
        }
        $this->hit($request);
        $token = WebContext::routeParam($request, 'token');
        $body = WebContext::body($request);
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $confirm = is_string($body['password_confirmation'] ?? null) ? $body['password_confirmation'] : '';

        $invitation = $this->members->findInvitation($token);
        if ($invitation === null) {
            return $this->invalid($request, 404);
        }
        $newAccount = !$this->members->hasAccount($invitation);
        if ($newAccount && $password !== $confirm) {
            return $this->form($request, $invitation, ['password_confirmation' => 'The passwords do not match.'], $name, 422);
        }
        try {
            $user = $this->members->accept($token, $name, $password, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->form($request, $invitation, $e->errors(), $name, 422);
        }
        if ($user === null) {
            return $this->invalid($request, 404);
        }
        $session = WebContext::session($request);
        if ($newAccount) {
            // The invitee has just chosen this password, so they are signed in straight away.
            $session->login($user->id);
            $this->auth->completeLogin($user, ClientInfo::fromRequest($request), false);
            $session->flash('status', sprintf('Your account is ready. Welcome to %s.', $invitation->workspaceName));

            return $this->responses->redirect('/');
        }
        $session->flash('status', sprintf('You joined %s. Sign in to open it.', $invitation->workspaceName));

        return $this->responses->redirect('/login');
    }

    /**
     * @param array<string, string> $errors
     */
    private function form(ServerRequestInterface $request, Invitation $invitation, array $errors, string $name, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('workspace/invitation', [
            'invitation' => $invitation,
            'newAccount' => !$this->members->hasAccount($invitation),
            'roleLabel' => MemberService::ASSIGNABLE_ROLES[$invitation->roleKey] ?? $invitation->roleKey,
            'errors' => $errors,
            'name' => $name,
        ], $request), $status)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'same-origin');
    }

    private function invalid(ServerRequestInterface $request, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('workspace/invitation-invalid', [
            'throttled' => $status === 429,
        ], $request), $status)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'same-origin');
    }

    private function throttled(ServerRequestInterface $request): bool
    {
        return $this->rateLimiter->peek($this->key($request), self::MAX_PER_IP)->hits >= self::MAX_PER_IP;
    }

    private function hit(ServerRequestInterface $request): void
    {
        $this->rateLimiter->hit($this->key($request), self::MAX_PER_IP, self::WINDOW_SECONDS);
    }

    private function key(ServerRequestInterface $request): string
    {
        return 'invite:ip:' . (ClientInfo::fromRequest($request)->ip ?? 'unknown');
    }
}
