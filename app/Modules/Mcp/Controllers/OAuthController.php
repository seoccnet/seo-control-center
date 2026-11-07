<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Workspaces\CurrentWorkspace;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Domain\Workspaces\WorkspaceMembershipView;
use SeoControlCenter\Modules\Mcp\OAuth\AuthorizationRequest;
use SeoControlCenter\Modules\Mcp\OAuth\OAuthError;
use SeoControlCenter\Modules\Mcp\OAuth\OAuthServer;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * The MCP sign-in for AI apps such as ChatGPT (ADR-029): discovery
 * documents, client registration and the token endpoint (public, JSON), and
 * the approval page for the signed-in user.
 */
final readonly class OAuthController
{
    public function __construct(
        private OAuthServer $server,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function protectedResource(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json($this->server->resourceMetadata());
    }

    public function authorizationServer(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json($this->server->serverMetadata());
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $input = json_decode((string) $request->getBody(), true);
        try {
            return $this->json($this->server->register(is_array($input) ? $input : [], ClientInfo::fromRequest($request)), 201);
        } catch (OAuthError $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    public function token(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->json($this->server->token(WebContext::body($request), $request->getHeaderLine('Authorization'), ClientInfo::fromRequest($request)));
        } catch (OAuthError $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    public function authorize(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $authorization = $this->server->authorizationRequest($request->getQueryParams());
        } catch (OAuthError $e) {
            return $this->fail($request, $e);
        }

        return $this->page($request, $authorization, null, 200);
    }

    public function decide(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        try {
            $authorization = $this->server->authorizationRequest($body);
        } catch (OAuthError $e) {
            return $this->fail($request, $e);
        }
        if (($body['decision'] ?? null) !== 'allow') {
            return $this->away($this->server->deny($authorization));
        }
        $workspace = null;
        foreach ($this->workspaces($request) as $candidate) {
            if ($candidate->publicId === ($body['workspace'] ?? null)) {
                $workspace = $candidate;
            }
        }
        if ($workspace === null) {
            return $this->page($request, $authorization, 'Choose a workspace.', 422);
        }
        $scopes = array_values(array_filter(is_array($body['scopes'] ?? null) ? $body['scopes'] : [], is_string(...)));
        try {
            return $this->away($this->server->approve(WebContext::user($request), $authorization, $workspace->id, $scopes, ClientInfo::fromRequest($request)));
        } catch (HttpException $e) {
            if ($e->statusCode() !== 422) {
                throw $e;
            }

            return $this->page($request, $authorization, $e->getMessage(), 422);
        }
    }

    private function page(ServerRequestInterface $request, AuthorizationRequest $authorization, ?string $error, int $status): ResponseInterface
    {
        $scopes = [];
        foreach (McpScope::cases() as $scope) {
            if (in_array($scope->value, $authorization->scopes, true)) {
                $scopes[$scope->value] = $scope->label();
            }
        }

        return $this->responses->html($this->views->render('oauth/authorize', [
            'authorization' => $authorization,
            'workspaces' => $this->workspaces($request),
            'current' => $request->getAttribute(RequestAttribute::WORKSPACE) instanceof CurrentWorkspace ? WebContext::workspace($request)->workspace()->publicId : null,
            'scopes' => $scopes,
            'returnHost' => (string) parse_url($authorization->redirectUri, PHP_URL_HOST),
            'error' => $error,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }

    private function fail(ServerRequestInterface $request, OAuthError $e): ResponseInterface
    {
        $redirect = $this->server->errorRedirect($e);
        if ($redirect !== null) {
            return $this->away($redirect);
        }

        return $this->responses->html($this->views->render('oauth/error', ['message' => $e->getMessage()], $request), 400);
    }

    /**
     * @return list<Workspace> the user's workspaces in which they may connect an app
     */
    private function workspaces(ServerRequestInterface $request): array
    {
        $context = $request->getAttribute(RequestAttribute::WORKSPACE);
        $all = $context instanceof CurrentWorkspace ? array_map(static fn(WorkspaceMembershipView $m): Workspace => $m->workspace, $context->all) : [];

        return $this->server->manageable(WebContext::user($request), $all);
    }

    /**
     * A redirect to the app (an allowed redirect URI, never a local path).
     */
    private function away(string $url): ResponseInterface
    {
        return $this->responses->empty(303)->withHeader('Location', $url)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->responses->json($data, $status)->withHeader('Cache-Control', 'no-store');
    }
}
