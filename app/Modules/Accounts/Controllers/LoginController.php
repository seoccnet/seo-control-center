<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Accounts\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Session\Session;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\AuthenticationService;
use SeoControlCenter\Domain\Accounts\LoginOutcome;
use SeoControlCenter\Domain\Accounts\MfaService;
use SeoControlCenter\Domain\Accounts\MfaVerification;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Sign-in in one or two steps: password, then a TOTP or recovery code when
 * MFA is enabled. Between the steps only a short-lived "pending" marker is
 * kept in the session; the user is not signed in until both succeed.
 */
final readonly class LoginController
{
    public const string PENDING_KEY = 'mfa_pending';
    public const int PENDING_TTL = 300;

    public function __construct(
        private AuthenticationService $auth,
        private MfaService $mfa,
        private UserRepository $users,
        private AuditLogger $audit,
        private Clock $clock,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getAttribute(RequestAttribute::USER) instanceof User) {
            return $this->responses->redirect('/');
        }

        return $this->form($request, '', null, 200);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        $email = is_string($body['email'] ?? null) ? mb_substr($body['email'], 0, 191) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $client = ClientInfo::fromRequest($request);

        $result = $this->auth->attempt($email, $password, $client);

        if ($result->outcome === LoginOutcome::Throttled) {
            return $this->form(
                $request,
                $email,
                sprintf('Too many sign-in attempts. Try again in %d minutes.', max(1, (int) ceil($result->retryAfterSeconds / 60))),
                429,
            )->withHeader('Retry-After', (string) $result->retryAfterSeconds);
        }
        if ($result->outcome !== LoginOutcome::Success || $result->user === null) {
            return $this->form($request, $email, 'The e-mail address or password is incorrect.', 422);
        }

        $session = WebContext::session($request);
        if ($this->mfa->isEnabled($result->user->id)) {
            $session->regenerate();
            $session->set(self::PENDING_KEY, [
                'user_id' => $result->user->id,
                'expires' => $this->clock->now()->getTimestamp() + self::PENDING_TTL,
            ]);

            return $this->responses->redirect('/login/verify');
        }

        $session->login($result->user->id);
        $this->auth->completeLogin($result->user, $client, false);

        return $this->responses->redirect(self::returnTo($session));
    }

    public function showVerify(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->pendingUser(WebContext::session($request)) === null) {
            return $this->responses->redirect('/login');
        }

        return $this->verifyForm($request, null, 200);
    }

    public function verify(ServerRequestInterface $request): ResponseInterface
    {
        $session = WebContext::session($request);
        $user = $this->pendingUser($session);
        if ($user === null) {
            return $this->responses->redirect('/login');
        }
        $code = WebContext::body($request)['code'] ?? '';
        $client = ClientInfo::fromRequest($request);

        return match ($this->mfa->verify($user, is_string($code) ? $code : '', $client)) {
            MfaVerification::Valid => $this->finishMfaLogin($session, $user, $client),
            MfaVerification::Throttled => $this->verifyForm($request, 'Too many attempts. Wait 15 minutes and try again.', 429),
            MfaVerification::Invalid => $this->verifyForm($request, 'That code is not valid. Check your authenticator app and try again.', 422),
        };
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        WebContext::session($request)->logout();
        $this->audit->record(
            new AuditEvent('auth.logout', ActorType::User, actorUserId: $user->id, actorLabel: $user->email),
            ClientInfo::fromRequest($request),
        );

        return $this->responses->redirect('/login');
    }

    private function finishMfaLogin(Session $session, User $user, ClientInfo $client): ResponseInterface
    {
        $session->remove(self::PENDING_KEY);
        $session->login($user->id);
        $this->auth->completeLogin($user, $client, true);

        return $this->responses->redirect(self::returnTo($session));
    }

    /**
     * The page that asked for the login, if it is a local path; otherwise the dashboard.
     */
    private static function returnTo(Session $session): string
    {
        $target = $session->get(Session::RETURN_TO);
        $session->remove(Session::RETURN_TO);

        return is_string($target) && str_starts_with($target, '/') && !str_starts_with($target, '//') && !str_contains($target, '\\')
            && preg_match('/[\x00-\x1F]/', $target) !== 1 ? $target : '/';
    }

    private function pendingUser(Session $session): ?User
    {
        $pending = $session->get(self::PENDING_KEY);
        if (!is_array($pending) || !is_int($pending['user_id'] ?? null) || !is_int($pending['expires'] ?? null)) {
            return null;
        }
        if ($pending['expires'] < $this->clock->now()->getTimestamp()) {
            $session->remove(self::PENDING_KEY);

            return null;
        }
        $user = $this->users->findById($pending['user_id']);

        return $user !== null && $user->active ? $user : null;
    }

    private function form(ServerRequestInterface $request, string $email, ?string $error, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('auth/login', [
            'email' => $email,
            'error' => $error,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }

    private function verifyForm(ServerRequestInterface $request, ?string $error, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('auth/verify', [
            'error' => $error,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }
}
