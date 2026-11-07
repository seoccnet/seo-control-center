<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mail\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Mail\MailException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Mail\MailSettingsService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * System → E-mail.
 */
final readonly class EmailSettingsController
{
    private const string PATH = '/system/email';

    public function __construct(
        private MailSettingsService $service,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, [], null, 200);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        try {
            $this->service->save(WebContext::user($request), $body, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), $body, 422);
        }
        WebContext::session($request)->flash('status', 'E-mail settings saved. Send a test e-mail to check them.');

        return $this->responses->redirect(self::PATH);
    }

    public function test(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        try {
            $this->service->sendTest($user, ClientInfo::fromRequest($request));
            WebContext::session($request)->flash('status', sprintf('Test e-mail sent to %s. Check the inbox and the spam folder.', $user->email));
        } catch (MailException $e) {
            WebContext::session($request)->flash('error', 'The test e-mail could not be sent: ' . $e->getMessage());
        }

        return $this->responses->redirect(self::PATH);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>|null $old
     */
    private function page(ServerRequestInterface $request, array $errors, ?array $old, int $status): ResponseInterface
    {
        $settings = $this->service->current(WebContext::user($request));

        return $this->responses->html($this->views->render('system/email', [
            'settings' => $settings,
            'hasPassword' => $settings->password !== null && $settings->password !== '',
            'old' => $old,
            'errors' => $errors,
            'adminEmail' => WebContext::user($request)->email,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }
}
