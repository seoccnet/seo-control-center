<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Modules\Assistant\AssistantService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * JSON endpoints of the floating chat. Errors are JSON too, so the chat can
 * show them: `{status: "error", error: "…"}` with the HTTP status.
 */
final readonly class AssistantController
{
    public function __construct(
        private AssistantService $service,
        private ResponseFactory $responses,
    ) {}

    public function state(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json(fn(): array => $this->service->state(WebContext::user($request), WebContext::workspace($request)->workspace()));
    }

    public function send(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);

        return $this->json(fn(): array => $this->service->send(
            WebContext::user($request),
            WebContext::workspace($request)->workspace(),
            self::string($body, 'message'),
            self::page($body),
            ClientInfo::fromRequest($request),
        ));
    }

    public function continue(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);

        return $this->json(fn(): array => $this->service->continue(WebContext::user($request), WebContext::workspace($request)->workspace(), self::string($body, 'conversation'), self::page($body)));
    }

    public function confirm(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);

        return $this->json(fn(): array => $this->service->confirm(
            WebContext::user($request),
            WebContext::workspace($request)->workspace(),
            self::string($body, 'conversation'),
            self::string($body, 'approve') === '1',
            self::page($body),
            ClientInfo::fromRequest($request),
        ));
    }

    public function plan(ServerRequestInterface $request): ResponseInterface
    {
        $plan = $this->service->fixPlan(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'plan'));

        return $this->responses->csv($plan->csvRows(), 'fix-plan-' . substr($plan->createdAt, 0, 10) . '.csv');
    }

    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json(fn(): array => $this->service->reset(WebContext::user($request), WebContext::workspace($request)->workspace()));
    }

    /**
     * @param \Closure(): array<string, mixed> $action
     */
    private function json(\Closure $action): ResponseInterface
    {
        try {
            return $this->responses->json($action());
        } catch (HttpException $e) {
            return $this->responses->json(['status' => 'error', 'error' => $e->statusCode() === 404 ? 'The AI assistant is not available here.' : $e->getMessage(), 'retryable' => false, 'retry_after' => $e->statusCode() === 429 ? 60 : 0], $e->statusCode());
        }
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function string(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? $body[$key] : '';
    }

    /**
     * The path of the page the chat is open on (context for the assistant).
     *
     * @param array<array-key, mixed> $body
     */
    private static function page(array $body): string
    {
        $page = self::string($body, 'page');

        return preg_match('#^/[A-Za-z0-9/_\-.]{0,200}$#D', $page) === 1 ? $page : '/';
    }
}
