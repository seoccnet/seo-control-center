<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\SearchConsole\SearchConsoleService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Workspace integrations page and the Google OAuth flow.
 */
final readonly class IntegrationsController
{
    public function __construct(
        private SearchConsoleService $service,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private LoggerInterface $logger,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $workspace = WebContext::workspace($request)->workspace();

        return $this->responses->html($this->views->render('integrations/index', [
            'connections' => $this->service->overview($user, $workspace),
            'canManage' => $this->service->canManage($user, $workspace),
            'configured' => $this->service->clientId() !== null,
        ], $request));
    }

    public function connect(ServerRequestInterface $request): ResponseInterface
    {
        $url = $this->service->startConnect(
            WebContext::user($request),
            WebContext::workspace($request)->workspace(),
            WebContext::session($request),
        );

        // External redirect to Google's consent page (fixed host, built by GoogleOAuth).
        return $this->responses->empty(303)->withHeader('Location', $url);
    }

    public function callback(ServerRequestInterface $request): ResponseInterface
    {
        $session = WebContext::session($request);
        try {
            $connection = $this->service->completeConnect(
                WebContext::user($request),
                WebContext::workspace($request)->workspace(),
                $session,
                $request->getQueryParams(),
                ClientInfo::fromRequest($request),
            );
            $session->flash('status', sprintf('Connected Google account %s.', $connection->accountEmail));
        } catch (IntegrationException $e) {
            $this->logger->warning('Google connection failed', ['exception' => $e]);
            $session->flash('error', SearchConsoleService::describe($e));
        } catch (HttpException $e) {
            if ($e->statusCode() === 403) {
                throw $e;
            }
            $session->flash('error', $e->getMessage());
        }

        return $this->responses->redirect('/integrations');
    }

    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $session = WebContext::session($request);
        try {
            $count = $this->service->refreshProperties(
                WebContext::user($request),
                WebContext::workspace($request)->workspace(),
                WebContext::routeParam($request, 'connection'),
            );
            $session->flash('status', sprintf('Found %d Search Console propert%s.', $count, $count === 1 ? 'y' : 'ies'));
        } catch (IntegrationException $e) {
            $session->flash('error', SearchConsoleService::describe($e));
        }

        return $this->responses->redirect('/integrations');
    }

    public function disconnect(ServerRequestInterface $request): ResponseInterface
    {
        $this->service->disconnect(
            WebContext::user($request),
            WebContext::workspace($request)->workspace(),
            WebContext::routeParam($request, 'connection'),
            ClientInfo::fromRequest($request),
        );
        WebContext::session($request)->flash('status', 'Google account disconnected. Imported data was kept.');

        return $this->responses->redirect('/integrations');
    }
}
