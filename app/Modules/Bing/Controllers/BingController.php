<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Bing\BingService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Workspace Bing Webmaster Tools settings: API key, sites, disconnect.
 */
final readonly class BingController
{
    private const string PATH = '/integrations/bing';

    public function __construct(
        private BingService $service,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private LoggerInterface $logger,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, [], 200);
    }

    public function connect(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        $session = WebContext::session($request);
        try {
            $sites = $this->service->connect(
                WebContext::user($request),
                WebContext::workspace($request)->workspace(),
                is_string($body['api_key'] ?? null) ? $body['api_key'] : '',
                ClientInfo::fromRequest($request),
            );
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), 422);
        } catch (IntegrationException $e) {
            $this->logger->warning('Bing connection failed', ['exception' => $e]);
            $session->flash('error', BingService::describe($e));

            return $this->responses->redirect(self::PATH);
        }
        $session->flash('status', $sites === []
            ? 'Bing connected, but the account has no verified sites yet. Verify your site in Bing Webmaster Tools, then press Refresh sites.'
            : sprintf('Bing connected with %d verified site%s. Link a project to a site under the project\'s settings.', count($sites), count($sites) === 1 ? '' : 's'));

        return $this->responses->redirect(self::PATH);
    }

    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $session = WebContext::session($request);
        try {
            $sites = $this->service->refreshSites(WebContext::user($request), WebContext::workspace($request)->workspace());
            $session->flash('status', sprintf('Connection works. %d verified site%s.', count($sites), count($sites) === 1 ? '' : 's'));
        } catch (IntegrationException $e) {
            $session->flash('error', BingService::describe($e));
        } catch (HttpException $e) {
            if ($e->statusCode() === 403) {
                throw $e;
            }
            $session->flash('error', $e->getMessage());
        }

        return $this->responses->redirect(self::PATH);
    }

    public function disconnect(ServerRequestInterface $request): ResponseInterface
    {
        $this->service->disconnect(WebContext::user($request), WebContext::workspace($request)->workspace(), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Bing disconnected. The API key was deleted; imported data was kept.');

        return $this->responses->redirect(self::PATH);
    }

    /**
     * @param array<string, string> $errors
     */
    private function page(ServerRequestInterface $request, array $errors, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('integrations/bing', [
            ...$this->service->overview(WebContext::user($request), WebContext::workspace($request)->workspace()),
            'errors' => $errors,
        ], $request), $status);
    }
}
