<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\SearchConsole\SearchConsoleService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Installation-level Google OAuth client settings (system administrators).
 */
final readonly class GoogleSettingsController
{
    public function __construct(
        private SearchConsoleService $service,
        private Authorizer $authorizer,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizer->authorize(WebContext::user($request)->subject(), Permission::SystemSettingsManage);

        return $this->form($request, [], null, 200);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        $clientId = is_string($body['client_id'] ?? null) ? $body['client_id'] : '';
        try {
            $this->service->saveClient(
                WebContext::user($request),
                $clientId,
                is_string($body['client_secret'] ?? null) ? $body['client_secret'] : '',
                ClientInfo::fromRequest($request),
            );
        } catch (ValidationException $e) {
            return $this->form($request, $e->errors(), $clientId, 422);
        }
        WebContext::session($request)->flash('status', 'Google OAuth client saved.');

        return $this->responses->redirect('/system/integrations/google');
    }

    /**
     * @param array<string, string> $errors
     */
    private function form(ServerRequestInterface $request, array $errors, ?string $clientId, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('integrations/google-settings', [
            'clientId' => $clientId ?? $this->service->clientId() ?? '',
            'hasSecret' => $this->service->clientId() !== null,
            'redirectUri' => $this->service->redirectUri(),
            'errors' => $errors,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }
}
