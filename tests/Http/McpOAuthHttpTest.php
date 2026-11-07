<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Modules\Mcp\OAuth\OAuthServer;
use SeoControlCenter\Tests\Support\Browser;
use SeoControlCenter\Tests\Support\Fakes\InMemoryMcpTokens;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * The MCP sign-in for AI apps such as ChatGPT (ADR-029): discovery,
 * registration, approval, PKCE, tokens, refresh, revocation and permissions.
 */
final class McpOAuthHttpTest extends TestCase
{
    private const string REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk-and-some-more-entropy';

    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testDiscoveryAndChallenge(): void
    {
        $browser = $this->s->browser();
        $resource = $this->json($browser->get('/.well-known/oauth-protected-resource'));
        self::assertSame(['http://scc.test/mcp', ['http://scc.test']], [$resource['resource'], $resource['authorization_servers']]);
        self::assertSame($resource, $this->json($browser->get('/.well-known/oauth-protected-resource/mcp')));
        $server = $this->json($browser->get('/.well-known/oauth-authorization-server'));
        self::assertSame('http://scc.test', $server['issuer']);
        self::assertSame(['S256'], $server['code_challenge_methods_supported']);
        self::assertTrue($server['authorization_response_iss_parameter_supported']);
        self::assertSame('http://scc.test/oauth/register', $server['registration_endpoint']);

        $unauthorized = $this->kernel('POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', ['Content-Type' => 'application/json']);
        self::assertSame(401, $unauthorized->getStatusCode());
        self::assertStringContainsString('resource_metadata="http://scc.test/.well-known/oauth-protected-resource"', $unauthorized->getHeaderLine('WWW-Authenticate'));
    }

    public function testChatGptSignsInAndUsesTheToken(): void
    {
        $client = $this->register();
        self::assertSame(['none', 'ChatGPT'], [$client['token_endpoint_auth_method'], $client['client_name']]);
        self::assertArrayNotHasKey('client_secret', $client);
        $authorize = $this->authorizeUrl($client['client_id']);

        // Not signed in yet: the login leads back to the sign-in request.
        $browser = $this->s->browser();
        self::assertSame('/login', $browser->get($authorize)->getHeaderLine('Location'));
        $login = $browser->login($this->s->owner->email, Scenario::PASSWORD);
        self::assertSame($authorize, $login->getHeaderLine('Location'));

        $page = (string) $browser->get($authorize)->getBody();
        self::assertStringContainsString('<h1>Connect ChatGPT</h1>', $page);
        self::assertStringContainsString('You then return to chatgpt.com.', $page);
        self::assertStringContainsString('value="' . $this->s->acme->publicId . '"', $page);

        $decision = $browser->post('/oauth/authorize', [...$this->query($client['client_id']), 'workspace' => $this->s->acme->publicId, 'scopes' => ['projects:read', 'opportunities:read'], 'decision' => 'allow']);
        self::assertSame(303, $decision->getStatusCode());
        $location = $decision->getHeaderLine('Location');
        self::assertStringStartsWith(self::REDIRECT . '?code=', $location);
        $back = self::back($location);
        self::assertSame(['xyz', 'http://scc.test'], [$back['state'], $back['iss']]);
        self::assertContains('mcp.oauth_approved', $this->s->audit()->actions());

        $tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $back['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER, 'client_id' => $client['client_id']]);
        self::assertSame(200, $tokens['status']);
        self::assertStringStartsWith('scc_', $tokens['access_token']);
        self::assertStringStartsWith('sccr_', $tokens['refresh_token']);
        self::assertSame(['Bearer', 'projects:read opportunities:read', 30 * 86400], [$tokens['token_type'], $tokens['scope'], $tokens['expires_in']]);
        self::assertSame('invalid_grant', $this->token(['grant_type' => 'authorization_code', 'code' => $back['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER, 'client_id' => $client['client_id']])['error'], 'a code works once');

        $tools = $this->tools($tokens['access_token']);
        self::assertContains('opportunities_list', $tools);
        self::assertNotContains('keywords_list', $tools, 'only the approved scopes');
        $listed = $this->s->container->get(InMemoryMcpTokens::class);
        self::assertInstanceOf(InMemoryMcpTokens::class, $listed);
        self::assertSame('ChatGPT (sign-in)', self::last($listed)->name);

        // Refresh: a new token, the old one stops working, a refresh token works once.
        $renewed = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client['client_id']]);
        self::assertSame(200, $renewed['status']);
        self::assertNotSame($tokens['access_token'], $renewed['access_token']);
        self::assertSame(401, $this->mcp($tokens['access_token'])->getStatusCode());
        self::assertContains('opportunities_list', $this->tools($renewed['access_token']));
        self::assertSame('invalid_grant', $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client['client_id']])['error']);

        // Revoking the token under AI access ends the connection.
        $listed->revoke(self::last($listed)->id);
        self::assertSame('invalid_grant', $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $renewed['refresh_token'], 'client_id' => $client['client_id']])['error']);
    }

    public function testRejectedRequests(): void
    {
        self::assertSame('invalid_redirect_uri', $this->json($this->kernel('POST', '/oauth/register', (string) json_encode(['client_name' => 'Evil', 'redirect_uris' => ['https://evil.test/callback']]), ['Content-Type' => 'application/json']))['error']);
        self::assertSame('invalid_redirect_uri', $this->json($this->kernel('POST', '/oauth/register', (string) json_encode(['redirect_uris' => ['http://chatgpt.com/cb']]), ['Content-Type' => 'application/json']))['error'], 'https only');
        $client = $this->register();
        $owner = $this->s->loggedIn($this->s->owner);

        $unknown = $owner->get('/oauth/authorize?' . http_build_query(['client_id' => '01a1016c-7171-70b3-bc34-476d9f88d499'] + $this->query('x')));
        self::assertSame(400, $unknown->getStatusCode(), 'never redirect for an unknown app');
        $foreign = $owner->get('/oauth/authorize?' . http_build_query(['redirect_uri' => 'https://chatgpt.com/other'] + $this->query($client['client_id'])));
        self::assertSame(400, $foreign->getStatusCode(), 'never redirect to an unregistered address');
        $noPkce = $owner->get('/oauth/authorize?' . http_build_query($this->query($client['client_id'], ['code_challenge', 'code_challenge_method'])));
        self::assertStringStartsWith(self::REDIRECT . '?error=invalid_request', $noPkce->getHeaderLine('Location'));
        self::assertStringContainsString('state=xyz', $noPkce->getHeaderLine('Location'));

        $owner->get($this->authorizeUrl($client['client_id']));
        $denied = $owner->post('/oauth/authorize', [...$this->query($client['client_id']), 'workspace' => $this->s->acme->publicId, 'decision' => 'deny']);
        self::assertStringStartsWith(self::REDIRECT . '?error=access_denied', $denied->getHeaderLine('Location'));

        // A wrong PKCE verifier.
        $owner->get($this->authorizeUrl($client['client_id']));
        $back = self::back($owner->post('/oauth/authorize', [...$this->query($client['client_id']), 'workspace' => $this->s->acme->publicId, 'scopes' => ['projects:read'], 'decision' => 'allow'])->getHeaderLine('Location'));
        self::assertSame('invalid_grant', $this->token(['grant_type' => 'authorization_code', 'code' => $back['code'], 'code_verifier' => str_repeat('a', 50), 'client_id' => $client['client_id']])['error']);
        self::assertSame('invalid_client', $this->token(['grant_type' => 'authorization_code', 'code' => 'x', 'client_id' => 'nope'])['error']);

        // Members cannot connect apps; the rival's workspace cannot be chosen.
        $member = $this->s->loggedIn($this->s->member);
        self::assertStringContainsString('Only owners and admins of a workspace can connect an AI app.', (string) $member->get($this->authorizeUrl($client['client_id']))->getBody());
        self::assertSame(422, $member->post('/oauth/authorize', [...$this->query($client['client_id']), 'workspace' => $this->s->acme->publicId, 'scopes' => ['projects:read'], 'decision' => 'allow'])->getStatusCode());
        $owner->get($this->authorizeUrl($client['client_id']));
        self::assertSame(422, $owner->post('/oauth/authorize', [...$this->query($client['client_id']), 'workspace' => $this->s->rival->publicId, 'scopes' => ['projects:read'], 'decision' => 'allow'])->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function register(): array
    {
        $response = $this->kernel('POST', '/oauth/register', (string) json_encode(['client_name' => 'ChatGPT', 'redirect_uris' => [self::REDIRECT], 'grant_types' => ['authorization_code', 'refresh_token'], 'token_endpoint_auth_method' => 'none']), ['Content-Type' => 'application/json']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /**
     * @param list<string> $without parameters to leave out
     * @return array<string, string>
     */
    private function query(string $clientId, array $without = []): array
    {
        return array_diff_key([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'state' => 'xyz',
            'code_challenge' => OAuthServer::challenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'scope' => 'projects:read keywords:read opportunities:read',
            'resource' => 'http://scc.test/mcp',
        ], array_flip($without));
    }

    /**
     * @return array{code: string, state: string, iss: string}
     */
    private static function back(string $location): array
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $value = static fn(string $key): string => is_string($query[$key] ?? null) ? $query[$key] : '';

        return ['code' => $value('code'), 'state' => $value('state'), 'iss' => $value('iss')];
    }

    private static function last(InMemoryMcpTokens $tokens): \SeoControlCenter\Domain\Mcp\McpToken
    {
        self::assertNotSame([], $tokens->tokens);

        return $tokens->tokens[array_key_last($tokens->tokens)];
    }

    private function authorizeUrl(string $clientId): string
    {
        return '/oauth/authorize?' . http_build_query($this->query($clientId));
    }

    /**
     * @param array<string, string> $data
     * @return array<string, mixed>
     */
    private function token(array $data): array
    {
        $response = (new Browser($this->s->container))->post('/oauth/token', $data, [], false);

        return ['status' => $response->getStatusCode()] + $this->json($response);
    }

    /**
     * @return list<string>
     */
    private function tools(string $token): array
    {
        $data = $this->json($this->mcp($token));
        self::assertIsArray($data['result']['tools'] ?? null, json_encode($data) ?: '');

        return array_column($data['result']['tools'], 'name');
    }

    private function mcp(string $token): ResponseInterface
    {
        return $this->kernel('POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . $token]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function kernel(string $method, string $path, string $body, array $headers): ResponseInterface
    {
        return $this->s->container->make(Kernel::class)->handle(
            (new ServerRequest($method, 'http://scc.test' . $path, $headers, null, '1.1', ['REMOTE_ADDR' => '203.0.113.30']))->withBody(Stream::create($body)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data, (string) $response->getBody());

        /** @var array<string, mixed> $data */
        return $data;
    }
}
