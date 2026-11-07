<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Integrations\BudgetPolicy;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\DataForSEO\DataForSeoService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Workspace DataForSEO settings: credentials, verification, budgets, usage.
 */
final readonly class DataForSeoController
{
    private const string PATH = '/integrations/dataforseo';

    public function __construct(
        private DataForSeoService $service,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private LoggerInterface $logger,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, [], [], 200);
    }

    public function connect(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        $session = WebContext::session($request);
        try {
            $info = $this->service->connect(
                WebContext::user($request),
                WebContext::workspace($request)->workspace(),
                is_string($body['login'] ?? null) ? $body['login'] : '',
                is_string($body['password'] ?? null) ? $body['password'] : '',
                ClientInfo::fromRequest($request),
            );
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), ['login' => is_string($body['login'] ?? null) ? $body['login'] : ''], 422);
        } catch (IntegrationException $e) {
            $this->logger->warning('DataForSEO connection failed', ['exception' => $e]);
            $session->flash('error', DataForSeoService::describe($e));

            return $this->responses->redirect(self::PATH);
        }
        $session->flash('status', $info->balance !== null
            ? sprintf('DataForSEO connected. Account balance: $%s.', BudgetPolicy::money($info->balance))
            : 'DataForSEO connected.');

        return $this->responses->redirect(self::PATH);
    }

    public function verify(ServerRequestInterface $request): ResponseInterface
    {
        $session = WebContext::session($request);
        try {
            $info = $this->service->verify(WebContext::user($request), WebContext::workspace($request)->workspace());
            $session->flash('status', $info->balance !== null
                ? sprintf('Connection works. Account balance: $%s.', BudgetPolicy::money($info->balance))
                : 'Connection works.');
        } catch (IntegrationException $e) {
            $session->flash('error', DataForSeoService::describe($e));
        } catch (HttpException $e) {
            if ($e->statusCode() === 403) {
                throw $e;
            }
            $session->flash('error', $e->getMessage());
        }

        return $this->responses->redirect(self::PATH);
    }

    public function budget(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        try {
            $this->service->saveBudget(
                WebContext::user($request),
                WebContext::workspace($request)->workspace(),
                $body,
                ClientInfo::fromRequest($request),
            );
        } catch (ValidationException $e) {
            $old = [];
            foreach (['daily_limit', 'monthly_limit', 'operation_limit'] as $field) {
                $old[$field] = is_string($body[$field] ?? null) ? $body[$field] : '';
            }

            return $this->page($request, $e->errors(), $old, 422);
        }
        WebContext::session($request)->flash('status', 'Budget saved.');

        return $this->responses->redirect(self::PATH);
    }

    public function disconnect(ServerRequestInterface $request): ResponseInterface
    {
        $this->service->disconnect(
            WebContext::user($request),
            WebContext::workspace($request)->workspace(),
            ClientInfo::fromRequest($request),
        );
        WebContext::session($request)->flash('status', 'DataForSEO disconnected. The API password was deleted; usage history was kept.');

        return $this->responses->redirect(self::PATH);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old submitted values to show again
     */
    private function page(ServerRequestInterface $request, array $errors, array $old, int $status): ResponseInterface
    {
        $overview = $this->service->overview(WebContext::user($request), WebContext::workspace($request)->workspace());

        return $this->responses->html($this->views->render('integrations/dataforseo', [
            ...$overview,
            'errors' => $errors,
            'old' => $old,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }
}
