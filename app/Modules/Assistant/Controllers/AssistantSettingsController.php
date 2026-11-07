<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Assistant\AssistantSetup;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Integrations → AI assistant: provider (Claude or GPT), API key, model, budgets.
 */
final readonly class AssistantSettingsController
{
    private const string PATH = '/integrations/assistant';

    public function __construct(
        private AssistantSetup $setup,
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
        try {
            $this->setup->connect(
                WebContext::user($request),
                WebContext::workspace($request)->workspace(),
                is_string($body['api_key'] ?? null) ? $body['api_key'] : '',
                is_string($body['model'] ?? null) ? $body['model'] : '',
                ClientInfo::fromRequest($request),
                is_string($body['provider_workspace'] ?? null) ? $body['provider_workspace'] : '',
            );
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), 422);
        } catch (IntegrationException $e) {
            $this->logger->warning('AI key check failed', ['exception' => $e]);
            WebContext::session($request)->flash('error', 'The provider could not be reached to check the key. Please try again in a few minutes.');

            return $this->responses->redirect(self::PATH);
        }
        WebContext::session($request)->flash('status', 'The AI assistant is ready: open it with the Ask AI button at the bottom right of any page.');

        return $this->responses->redirect(self::PATH);
    }

    public function model(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        try {
            $this->setup->setModel(WebContext::user($request), WebContext::workspace($request)->workspace(), is_string($body['model'] ?? null) ? $body['model'] : '', ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), 422);
        }
        WebContext::session($request)->flash('status', 'Model saved.');

        return $this->responses->redirect(self::PATH);
    }

    public function budget(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->setup->saveBudget(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::body($request), ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), 422);
        }
        WebContext::session($request)->flash('status', 'Budgets saved.');

        return $this->responses->redirect(self::PATH);
    }

    public function disconnect(ServerRequestInterface $request): ResponseInterface
    {
        $this->setup->disconnect(WebContext::user($request), WebContext::workspace($request)->workspace(), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'The API key was deleted. Conversations and usage history were kept.');

        return $this->responses->redirect(self::PATH);
    }

    /**
     * @param array<string, string> $errors
     */
    private function page(ServerRequestInterface $request, array $errors, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('integrations/assistant', [
            ...$this->setup->overview(WebContext::user($request), WebContext::workspace($request)->workspace()),
            'errors' => $errors,
            'old' => WebContext::body($request),
        ], $request), $status);
    }
}
