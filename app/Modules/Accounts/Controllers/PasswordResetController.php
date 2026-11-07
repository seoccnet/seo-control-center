<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Accounts\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Session\SessionStore;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\PasswordResetService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Public "forgot password" pages. After a reset, every session of the user is
 * signed out and they sign in with the new password.
 */
final readonly class PasswordResetController
{
    public function __construct(
        private PasswordResetService $resets,
        private SessionStore $sessions,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function showRequest(ServerRequestInterface $request): ResponseInterface
    {
        return $this->requestPage($request, '', null, false, 200);
    }

    public function request(ServerRequestInterface $request): ResponseInterface
    {
        $email = WebContext::body($request)['email'] ?? '';
        $email = is_string($email) ? mb_substr(trim($email), 0, 191) : '';
        if (!$this->resets->isAvailable()) {
            return $this->requestPage($request, $email, null, false, 200);
        }
        if (!$this->resets->request($email, ClientInfo::fromRequest($request))) {
            return $this->requestPage($request, $email, 'Too many requests. Wait an hour and try again.', false, 429);
        }

        return $this->requestPage($request, '', null, true, 200);
    }

    public function showReset(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->resets->userForToken(WebContext::routeParam($request, 'token'));
        if ($user === null) {
            return $this->invalid($request);
        }

        return $this->resetPage($request, $user->email, [], 200);
    }

    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        $token = WebContext::routeParam($request, 'token');
        $body = WebContext::body($request);
        try {
            $user = $this->resets->reset(
                $token,
                is_string($body['password'] ?? null) ? $body['password'] : '',
                is_string($body['password_confirmation'] ?? null) ? $body['password_confirmation'] : '',
                ClientInfo::fromRequest($request),
            );
        } catch (ValidationException $e) {
            $user = $this->resets->userForToken($token);

            return $user !== null ? $this->resetPage($request, $user->email, $e->errors(), 422) : $this->invalid($request);
        }
        if ($user === null) {
            return $this->invalid($request);
        }
        $this->sessions->destroyForUser($user->id);
        $session = WebContext::session($request);
        $session->regenerate();
        $session->flash('status', 'Your password was changed. Sign in with the new password.');

        return $this->responses->redirect('/login');
    }

    private function requestPage(ServerRequestInterface $request, string $email, ?string $error, bool $sent, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('auth/forgot', [
            'available' => $this->resets->isAvailable(),
            'email' => $email,
            'error' => $error,
            'sent' => $sent,
            'minutes' => PasswordResetService::VALID_MINUTES,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, string> $errors
     */
    private function resetPage(ServerRequestInterface $request, string $email, array $errors, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('auth/reset', [
            'email' => $email,
            'errors' => $errors,
        ], $request), $status)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'same-origin');
    }

    private function invalid(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->html($this->views->render('auth/reset-invalid', [], $request), 404)
            ->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'same-origin');
    }
}
