<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\Mcp\McpTokenService;
use SeoControlCenter\Modules\Mcp\OAuth\OAuthServer;
use SeoControlCenter\Modules\Mcp\Server\McpServer;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

/**
 * The MCP endpoint (Streamable HTTP transport, JSON responses only, no
 * server-sent events): POST /mcp with a bearer token. Stateless, so it
 * works on shared hosting without long-running connections.
 */
final readonly class McpEndpointController
{
    private const int MAX_BODY_BYTES = 1_000_000;
    private const int MAX_FAILED_PER_MINUTE = 30;

    public function __construct(
        private McpTokenService $tokens,
        private McpServer $server,
        private ProjectRepository $projects,
        private Authorizer $authorizer,
        private RateLimiter $rateLimiter,
        private ResponseFactory $responses,
        private string $appUrl,
        private FeatureManager $features,
    ) {}

    public function post(ServerRequestInterface $request): ResponseInterface
    {
        // DNS-rebinding protection (MCP transport spec): browsers send Origin; it must be ours.
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && strcasecmp(rtrim($origin, '/'), self::origin($this->appUrl)) !== 0) {
            return $this->json(McpServer::error(null, -32600, 'Origin not allowed.'), 403);
        }

        // Failed attempts per IP are limited, so wrong tokens cannot hammer the database.
        $failedKey = 'mcp:auth-failed:' . (ClientInfo::fromRequest($request)->ip ?? 'unknown');
        if (!$this->rateLimiter->peek($failedKey, self::MAX_FAILED_PER_MINUTE - 1)->allowed) {
            return $this->json(McpServer::error(null, -32002, 'Too many failed authentication attempts. Try again in a minute.'), 429)
                ->withHeader('Retry-After', '60');
        }
        $header = $request->getHeaderLine('Authorization');
        $access = str_starts_with($header, 'Bearer ') ? $this->tokens->authenticate(trim(substr($header, 7))) : null;
        if ($access === null) {
            $this->rateLimiter->hit($failedKey, self::MAX_FAILED_PER_MINUTE, 60);

            return $this->json(McpServer::error(null, -32001, 'Missing, invalid, expired or revoked token.'), 401)
                ->withHeader('WWW-Authenticate', sprintf('Bearer realm="seo-control-center", resource_metadata="%s/.well-known/oauth-protected-resource", scope="%s"', rtrim($this->appUrl, '/'), implode(' ', OAuthServer::allScopes())));
        }

        $limit = $this->rateLimiter->hit('mcp:token:' . $access->token->id, $access->token->rateLimitPerMinute, 60);
        if (!$limit->allowed) {
            return $this->json(McpServer::error(null, -32002, 'Rate limit reached. Try again in a minute.'), 429)
                ->withHeader('Retry-After', '60');
        }

        if (!$this->features->enabled(Feature::Mcp)) {
            return $this->json(McpServer::error(null, -32003, 'AI access (MCP) is turned off on this installation.'), 403);
        }

        $body = (string) $request->getBody();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->json(McpServer::error(null, -32600, 'Request too large.'), 413);
        }
        try {
            $message = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(McpServer::error(null, -32700, 'Parse error: the body must be JSON.'), 400);
        }

        $response = $this->server->handle(
            $message,
            new ToolContext($access, $this->projects, $this->authorizer, ClientInfo::fromRequest($request)),
            ClientInfo::fromRequest($request),
        );

        return $response === null ? $this->responses->empty(202) : $this->json($response, 200);
    }

    /**
     * Server-sent event streams and sessions are not offered (stateless server).
     */
    public function notAllowed(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->empty(405)->withHeader('Allow', 'POST');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status): ResponseInterface
    {
        return $this->responses->json($data, $status)->withHeader('Cache-Control', 'no-store');
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    }
}
