<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Mcp\McpTokenService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * AI access (MCP) tokens of the current workspace. A new token is rendered
 * once in the response to its creation, never stored or flashed.
 */
final readonly class McpTokensController
{
    private const string PATH = '/integrations/mcp';

    public function __construct(
        private McpTokenService $tokens,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private string $appUrl,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, [], [], null, 200);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        try {
            $created = $this->tokens->create(WebContext::user($request), WebContext::workspace($request)->workspace(), $body, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), $body, null, 422);
        }

        return $this->page($request, [], [], $created['plaintext'], 201);
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->tokens->revoke(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'token'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', sprintf('Token "%s" revoked. Clients using it lose access immediately.', $token->name));

        return $this->responses->redirect(self::PATH);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function page(ServerRequestInterface $request, array $errors, array $old, ?string $newToken, int $status): ResponseInterface
    {
        $user = WebContext::user($request);
        $workspace = WebContext::workspace($request)->workspace();

        return $this->responses->html($this->views->render('integrations/mcp', [
            'tokens' => $this->tokens->list($user, $workspace),
            'projects' => $this->projects->visibleProjects($user, $workspace),
            'scopes' => McpScope::cases(),
            'expiryDays' => McpTokenService::EXPIRY_DAYS,
            'endpoint' => rtrim($this->appUrl, '/') . '/mcp',
            'newToken' => $newToken,
            'errors' => $errors,
            'old' => $old,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }
}
