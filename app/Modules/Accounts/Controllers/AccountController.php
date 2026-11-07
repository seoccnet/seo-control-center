<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Accounts\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Session\SessionStore;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\AccountService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * The user's own account: name, e-mail address and password. After a
 * password change every other session of the user is signed out.
 */
final readonly class AccountController
{
    private const string PATH = '/account';

    public function __construct(
        private AccountService $accounts,
        private SessionStore $sessions,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, [], [], 200);
    }

    public function profile(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        try {
            $this->accounts->updateProfile(
                WebContext::user($request),
                self::field($body, 'name'),
                self::field($body, 'email'),
                self::field($body, 'current_password_profile'),
                ClientInfo::fromRequest($request),
            );
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), ['name' => self::field($body, 'name'), 'email' => self::field($body, 'email')], 422);
        }
        WebContext::session($request)->flash('status', 'Your profile was saved.');

        return $this->responses->redirect(self::PATH);
    }

    public function password(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        $user = WebContext::user($request);
        try {
            $this->accounts->changePassword(
                $user,
                self::field($body, 'current_password'),
                self::field($body, 'new_password'),
                self::field($body, 'new_password_confirmation'),
                ClientInfo::fromRequest($request),
            );
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), [], 422);
        }
        $session = WebContext::session($request);
        $others = $this->sessions->destroyForUser($user->id, $session);
        $session->regenerate();
        $session->flash('status', $others > 0
            ? sprintf('Your password was changed. %d other session(s) were signed out.', $others)
            : 'Your password was changed.');

        return $this->responses->redirect(self::PATH);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function page(ServerRequestInterface $request, array $errors, array $old, int $status): ResponseInterface
    {
        $user = WebContext::user($request);

        return $this->responses->html($this->views->render('account/profile', [
            'name' => $old['name'] ?? $user->name,
            'email' => $old['email'] ?? $user->email,
            'currentEmail' => $user->email,
            'errors' => $errors,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function field(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? $body[$key] : '';
    }
}
