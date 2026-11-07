<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\OAuth;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Mcp\McpTokenRepository;
use SeoControlCenter\Modules\Mcp\McpTokenService;

/**
 * OAuth 2.1 authorization server for MCP clients such as ChatGPT (ADR-029):
 *
 * - discovery: protected resource metadata (RFC 9728) and authorization
 *   server metadata (RFC 8414), with issuer identification (RFC 9207);
 * - dynamic client registration (RFC 7591), limited to redirect URIs on
 *   the configured AI app hosts, so the sign-in cannot be abused to send
 *   codes elsewhere;
 * - the authorization code flow with PKCE S256 only, approved by a signed-in
 *   owner or admin (tokens.manage) for one workspace and chosen scopes;
 * - the token endpoint, which issues an ordinary MCP token (shown in
 *   Integrations → AI access and revocable there) plus a rotating refresh token.
 */
final readonly class OAuthServer
{
    public const int CODE_SECONDS = 600;
    public const string REFRESH_PREFIX = 'sccr_';
    private const array AUTH_METHODS = ['none', 'client_secret_post', 'client_secret_basic'];

    /**
     * @param list<string> $redirectHosts hosts allowed in registered redirect URIs
     */
    public function __construct(
        private OAuthRepository $oauth,
        private McpTokenRepository $tokens,
        private UserRepository $users,
        private Authorizer $authorizer,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
        private LoggerInterface $logger,
        private string $appUrl,
        private array $redirectHosts,
        private int $accessTokenDays,
        private int $refreshTokenDays,
    ) {}

    public function issuer(): string
    {
        return rtrim($this->appUrl, '/');
    }

    public function resource(): string
    {
        return $this->issuer() . '/mcp';
    }

    public function resourceMetadataUrl(): string
    {
        return $this->issuer() . '/.well-known/oauth-protected-resource';
    }

    /**
     * @return array<string, mixed> RFC 9728
     */
    public function resourceMetadata(): array
    {
        return [
            'resource' => $this->resource(),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => self::allScopes(),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'SEO Control Center',
        ];
    }

    /**
     * @return array<string, mixed> RFC 8414
     */
    public function serverMetadata(): array
    {
        return [
            'issuer' => $this->issuer(),
            'authorization_endpoint' => $this->issuer() . '/oauth/authorize',
            'token_endpoint' => $this->issuer() . '/oauth/token',
            'registration_endpoint' => $this->issuer() . '/oauth/register',
            'scopes_supported' => self::allScopes(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => self::AUTH_METHODS,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * Dynamic client registration (RFC 7591).
     *
     * @param array<array-key, mixed> $input
     * @return array<string, mixed>
     * @throws OAuthError
     */
    public function register(array $input, ClientInfo $client): array
    {
        if (!$this->rateLimiter->hit('mcp:oauth-register:' . ($client->ip ?? 'unknown'), 20, 3600)->allowed) {
            throw new OAuthError('invalid_request', 'Too many registrations from this address. Try again later.', 429);
        }
        $uris = is_array($input['redirect_uris'] ?? null) ? array_values(array_unique(array_filter($input['redirect_uris'], is_string(...)))) : [];
        if ($uris === [] || count($uris) > 10) {
            throw new OAuthError('invalid_redirect_uri', 'Send one to ten redirect_uris.');
        }
        foreach ($uris as $uri) {
            if (!$this->allowedRedirect($uri)) {
                throw new OAuthError('invalid_redirect_uri', sprintf('Redirect URIs must use https on one of these hosts: %s.', implode(', ', $this->redirectHosts)));
            }
        }
        $method = is_string($input['token_endpoint_auth_method'] ?? null) ? $input['token_endpoint_auth_method'] : 'none';
        if (!in_array($method, self::AUTH_METHODS, true)) {
            throw new OAuthError('invalid_client_metadata', 'token_endpoint_auth_method must be one of: ' . implode(', ', self::AUTH_METHODS) . '.');
        }
        $name = is_string($input['client_name'] ?? null) && trim($input['client_name']) !== '' ? mb_substr(trim($input['client_name']), 0, 120) : 'AI app';
        $secret = $method === 'none' ? null : rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $registered = $this->oauth->registerClient($name, $uris, $method, $secret !== null ? hash('sha256', $secret, true) : null, $client->ip);
        $this->logger->info('MCP OAuth client registered', ['client_id' => $registered->clientId, 'name' => $name]);

        return array_filter([
            'client_id' => $registered->clientId,
            'client_id_issued_at' => $this->clock->now()->getTimestamp(),
            'client_secret' => $secret,
            'client_secret_expires_at' => $secret !== null ? 0 : null,
            'client_name' => $name,
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $method,
        ], static fn(mixed $v): bool => $v !== null);
    }

    /**
     * Validates the authorization endpoint's query.
     *
     * @param array<array-key, mixed> $query
     * @throws OAuthError
     */
    public function authorizationRequest(array $query): AuthorizationRequest
    {
        $string = static fn(string $key): ?string => is_string($query[$key] ?? null) && $query[$key] !== '' ? $query[$key] : null;
        $client = $this->oauth->findClient($string('client_id') ?? '');
        if ($client === null) {
            throw new OAuthError('invalid_client', 'This app is not registered with SEO Control Center. Remove the connection in the app and add it again.');
        }
        $redirectUri = $string('redirect_uri') ?? (count($client->redirectUris) === 1 ? $client->redirectUris[0] : null);
        if ($redirectUri === null || !in_array($redirectUri, $client->redirectUris, true)) {
            throw new OAuthError('invalid_request', 'The redirect address of this sign-in request is not registered for the app.');
        }
        $state = $string('state');
        $fail = static fn(string $error, string $description): OAuthError => new OAuthError($error, $description, 302, true, $redirectUri, $state);
        if ($string('response_type') !== 'code') {
            throw $fail('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = $string('code_challenge');
        if ($challenge === null || $string('code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $challenge) !== 1) {
            throw $fail('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }
        $resource = $string('resource');
        if ($resource !== null && !in_array(rtrim($resource, '/'), [$this->resource(), $this->issuer()], true)) {
            throw $fail('invalid_target', 'This server only grants access to ' . $this->resource() . '.');
        }
        $scopes = self::scopesFrom($string('scope') ?? '');
        if ($string('scope') !== null && $scopes === []) {
            throw $fail('invalid_scope', 'None of the requested scopes is known.');
        }

        return new AuthorizationRequest($client, $redirectUri, $state, $challenge, $scopes !== [] ? $scopes : self::allScopes(), $resource);
    }

    /**
     * The user approved: a one-time code goes back to the app.
     *
     * @param list<string> $scopes the scopes the user left checked
     * @return string the redirect URL
     */
    public function approve(User $user, AuthorizationRequest $request, int $workspaceId, array $scopes, ClientInfo $client): string
    {
        if (!$this->authorizer->can($user->subject(), Permission::TokensManage, $workspaceId)) {
            throw new HttpException(403, 'Only owners and admins of the workspace can connect an AI app.');
        }
        $scopes = array_values(array_intersect($request->scopes, $scopes));
        if ($scopes === []) {
            throw new HttpException(422, 'Choose at least one kind of data.');
        }
        $code = self::random();
        $this->oauth->createCode(hash('sha256', $code, true), $request->client->id, $user->id, $workspaceId, $scopes, $request->redirectUri, $request->codeChallenge, $this->clock->now()->modify('+' . self::CODE_SECONDS . ' seconds')->format('Y-m-d H:i:s'));
        $this->audit->record(new AuditEvent('mcp.oauth_approved', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspaceId, targetType: 'mcp_oauth_client', targetId: $request->client->clientId, metadata: [
            'app' => $request->client->name,
            'scopes' => $scopes,
        ]), $client);

        return $this->redirect($request, ['code' => $code]);
    }

    public function deny(AuthorizationRequest $request): string
    {
        return $this->redirect($request, ['error' => 'access_denied', 'error_description' => 'The user did not allow access.']);
    }

    /**
     * Where to send a redirectable authorization error, or null to show it to the user.
     */
    public function errorRedirect(OAuthError $error): ?string
    {
        if (!$error->redirectable || $error->redirectUri === null) {
            return null;
        }
        $uri = $error->redirectUri;

        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query(array_filter(['error' => $error->error, 'error_description' => $error->getMessage(), 'state' => $error->state, 'iss' => $this->issuer()], static fn(?string $v): bool => $v !== null));
    }

    /**
     * The workspaces in which the user may connect an AI app (tokens.manage).
     *
     * @param list<\SeoControlCenter\Domain\Workspaces\Workspace> $workspaces
     * @return list<\SeoControlCenter\Domain\Workspaces\Workspace>
     */
    public function manageable(User $user, array $workspaces): array
    {
        return array_values(array_filter($workspaces, fn(\SeoControlCenter\Domain\Workspaces\Workspace $w): bool => $this->authorizer->can($user->subject(), Permission::TokensManage, $w->id)));
    }

    /**
     * The token endpoint.
     *
     * @param array<array-key, mixed> $body form fields
     * @return array<string, mixed>
     * @throws OAuthError
     */
    public function token(array $body, #[SensitiveParameter] string $authorization, ClientInfo $client): array
    {
        $string = static fn(string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
        [$clientId, $secret] = [$string('client_id'), $string('client_secret')];
        if (str_starts_with($authorization, 'Basic ')) {
            $decoded = base64_decode(substr($authorization, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$clientId, $secret] = array_map(urldecode(...), explode(':', $decoded, 2));
            }
        }
        if (!$this->rateLimiter->hit('mcp:oauth-token:' . ($client->ip ?? 'unknown'), 60, 60)->allowed) {
            throw new OAuthError('invalid_request', 'Too many token requests. Try again in a minute.', 429);
        }
        $app = $this->oauth->findClient($clientId);
        if ($app === null || ($app->authMethod !== 'none' && ($app->secretHash === null || !hash_equals($app->secretHash, hash('sha256', $secret, true))))) {
            throw new OAuthError('invalid_client', 'Unknown client or wrong client secret.', 401);
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->oauth->deleteExpired($this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s'));

        return match ($string('grant_type')) {
            'authorization_code' => $this->exchangeCode($app, $string('code'), $string('redirect_uri'), $string('code_verifier'), $now),
            'refresh_token' => $this->refresh($app, $string('refresh_token'), $now),
            default => throw new OAuthError('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCode(OAuthClient $app, #[SensitiveParameter] string $code, string $redirectUri, #[SensitiveParameter] string $verifier, string $now): array
    {
        $grant = $code !== '' ? $this->oauth->consumeCode(hash('sha256', $code, true), $now) : null;
        if ($grant === null || $grant['clientId'] !== $app->id) {
            throw new OAuthError('invalid_grant', 'The code is unknown, expired or already used.');
        }
        if ($redirectUri !== '' && $redirectUri !== $grant['redirectUri']) {
            throw new OAuthError('invalid_grant', 'redirect_uri does not match the sign-in request.');
        }
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1 || !hash_equals($grant['codeChallenge'], self::challenge($verifier))) {
            throw new OAuthError('invalid_grant', 'code_verifier does not match the code_challenge.');
        }

        return $this->issue($app, $grant['userId'], $grant['workspaceId'], $grant['scopes']);
    }

    /**
     * @return array<string, mixed>
     */
    private function refresh(OAuthClient $app, #[SensitiveParameter] string $refreshToken, string $now): array
    {
        $grant = str_starts_with($refreshToken, self::REFRESH_PREFIX) ? $this->oauth->consumeRefreshToken(hash('sha256', $refreshToken, true), $now) : null;
        if ($grant === null || $grant['clientId'] !== $app->id) {
            throw new OAuthError('invalid_grant', 'The refresh token is unknown, expired or already used.');
        }
        // Revoking the token in AI access ends the connection: it cannot be renewed.
        $previous = $grant['mcpTokenId'] !== null ? $this->tokens->findById($grant['mcpTokenId']) : null;
        if ($previous === null || $previous->revokedAt !== null) {
            throw new OAuthError('invalid_grant', 'Access was revoked in SEO Control Center. Connect the app again.');
        }
        $this->tokens->revoke($previous->id);

        return $this->issue($app, $grant['userId'], $grant['workspaceId'], $grant['scopes']);
    }

    /**
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    private function issue(OAuthClient $app, int $userId, int $workspaceId, array $scopes): array
    {
        $user = $this->users->findById($userId);
        if ($user === null || !$user->active || !$this->authorizer->can($user->subject(), Permission::TokensManage, $workspaceId)) {
            throw new OAuthError('invalid_grant', 'The user who allowed access can no longer grant it.');
        }
        $plaintext = McpTokenService::PREFIX . self::random();
        $expires = $this->clock->now()->modify('+' . max(1, $this->accessTokenDays) . ' days');
        $token = $this->tokens->create(
            $workspaceId,
            $userId,
            $app->name . ' (sign-in)',
            hash('sha256', $plaintext, true),
            substr($plaintext, 0, 12),
            array_values(array_filter(array_map(McpScope::tryFrom(...), $scopes))),
            null,
            McpTokenService::RATE_LIMIT_PER_MINUTE,
            $expires->format('Y-m-d H:i:s'),
        );
        $refresh = self::REFRESH_PREFIX . self::random();
        $this->oauth->createRefreshToken(hash('sha256', $refresh, true), $app->id, $token->id, $userId, $workspaceId, $scopes, $this->clock->now()->modify('+' . max(1, $this->refreshTokenDays) . ' days')->format('Y-m-d H:i:s'));
        $this->oauth->touchClient($app->id);
        $this->audit->record(new AuditEvent('mcp.token_created', ActorType::User, actorUserId: $userId, actorLabel: $user->email, workspaceId: $workspaceId, targetType: 'mcp_token', targetId: $token->publicId, metadata: [
            'name' => $token->name,
            'via' => 'oauth',
            'scopes' => $scopes,
            'expires_at' => $token->expiresAt,
        ]));

        return [
            'access_token' => $plaintext,
            'token_type' => 'Bearer',
            'expires_in' => $expires->getTimestamp() - $this->clock->now()->getTimestamp(),
            'refresh_token' => $refresh,
            'scope' => implode(' ', $scopes),
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function redirect(AuthorizationRequest $request, array $parameters): string
    {
        $query = http_build_query([...$parameters, ...array_filter(['state' => $request->state]), 'iss' => $this->issuer()]);

        return $request->redirectUri . (str_contains($request->redirectUri, '?') ? '&' : '?') . $query;
    }

    private function allowedRedirect(string $uri): bool
    {
        $parts = parse_url($uri);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && !isset($parts['fragment']) && !isset($parts['user'])
            && in_array(strtolower($parts['host'] ?? ''), $this->redirectHosts, true) && strlen($uri) <= 500;
    }

    /**
     * @return list<string>
     */
    public static function allScopes(): array
    {
        return array_map(static fn(McpScope $s): string => $s->value, McpScope::cases());
    }

    /**
     * @return list<string>
     */
    public static function scopesFrom(string $scope): array
    {
        return array_values(array_intersect(self::allScopes(), preg_split('/\s+/', trim($scope)) ?: []));
    }

    public static function challenge(#[SensitiveParameter] string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
