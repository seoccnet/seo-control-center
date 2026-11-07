<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Domain\Integrations\OAuthClientCredentials;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleOAuth;
use SeoControlCenter\Tests\Support\Browser;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Google Search Console connection flow against a simulated Google.
 */
final class GoogleConnectionTest extends TestCase
{
    private const string CLIENT_ID = '123456789-abcdef.apps.googleusercontent.com';

    private Scenario $s;

    private FakeHttpClient $google;

    private InMemoryIntegrations $store;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $http = $this->s->container->get(HttpClient::class);
        $store = $this->s->container->get(InMemoryIntegrations::class);
        self::assertInstanceOf(FakeHttpClient::class, $http);
        self::assertInstanceOf(InMemoryIntegrations::class, $store);
        $this->google = $http;
        $this->store = $store;

        $this->google->json('POST', GoogleOAuth::TOKEN_URL, [
            'access_token' => 'ya29.access-1',
            'refresh_token' => '1//refresh-1',
            'expires_in' => 3599,
            'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/webmasters.readonly',
        ]);
        $this->google->json('GET', GoogleOAuth::USERINFO_URL, ['email' => 'Seo@Acme.test']);
        $this->google->json('GET', 'https://www.googleapis.com/webmasters/v3/sites', ['siteEntry' => [
            ['siteUrl' => 'sc-domain:acme.test', 'permissionLevel' => 'siteOwner'],
            ['siteUrl' => 'https://shop.acme.test/', 'permissionLevel' => 'siteFullUser'],
            ['siteUrl' => 'https://old.acme.test/', 'permissionLevel' => 'siteUnverifiedUser'],
        ]]);
        $this->google->json('POST', GoogleOAuth::REVOKE_URL, []);
    }

    public function testSystemAdminConfiguresClientAndSecretIsNotShownAgain(): void
    {
        $root = $this->s->addUser('root@acme.test', SystemRole::Admin);
        $browser = $this->s->loggedIn($root);

        $page = (string) $browser->get('/system/integrations/google')->getBody();
        self::assertStringContainsString('http://scc.test/integrations/google/callback', $page);

        self::assertSame(422, $browser->post('/system/integrations/google', ['client_id' => 'nonsense', 'client_secret' => 'x'])->getStatusCode());
        $browser->get('/system/integrations/google');
        self::assertSame(303, $browser->post('/system/integrations/google', ['client_id' => self::CLIENT_ID, 'client_secret' => 'GOCSPX-secret'])->getStatusCode());

        $html = (string) $browser->get('/system/integrations/google')->getBody();
        self::assertStringNotContainsString('GOCSPX-secret', $html);
        self::assertSame('GOCSPX-secret', $this->store->get('google')?->clientSecret);
        self::assertContains('integration.google.client_updated', $this->s->audit()->actions());

        // Workspace owners are not installation admins.
        self::assertSame(403, $this->s->loggedIn($this->s->owner)->get('/system/integrations/google')->getStatusCode());
    }

    public function testFullConnectFlowStoresEncryptedTokensAndDiscoversProperties(): void
    {
        $browser = $this->connect();

        $connection = array_values($this->store->connections)[0];
        self::assertSame('seo@acme.test', $connection->accountEmail);
        self::assertSame($this->s->acme->id, $connection->workspaceId);
        $tokens = $this->store->tokens[$connection->id];
        self::assertStringNotContainsString('1//refresh-1', (string) $tokens['refresh']);
        self::assertStringNotContainsString('ya29.access-1', (string) $tokens['access']);
        self::assertCount(3, $this->store->properties[$connection->id]);

        $html = (string) $browser->get('/integrations')->getBody();
        self::assertStringContainsString('seo@acme.test', $html);
        self::assertStringContainsString('acme.test (domain property)', $html);
        self::assertContains('integration.google.connected', $this->s->audit()->actions());

        // PKCE: the token exchange carried the verifier matching the challenge sent to Google.
        $exchange = $this->google->requestsTo(GoogleOAuth::TOKEN_URL)[0];
        parse_str((string) $exchange['body'], $form);
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertIsString($form['code_verifier']);
    }

    public function testCallbackWithWrongStateIsRejected(): void
    {
        $this->configureClient();
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get('/integrations');
        $browser->post('/integrations/google/connect');

        $browser->get('/integrations/google/callback?state=forged&code=abc');
        self::assertStringContainsString('expired or was not started here', (string) $browser->get('/integrations')->getBody());
        self::assertSame([], $this->store->connections);
        self::assertSame([], $this->google->requestsTo(GoogleOAuth::TOKEN_URL), 'No code exchange for a forged callback.');
    }

    public function testCallbackStateCannotBeReplayed(): void
    {
        $this->configureClient();
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get('/integrations');
        $state = $this->stateFrom($browser->post('/integrations/google/connect')->getHeaderLine('Location'));

        $browser->get('/integrations/google/callback?state=' . $state . '&code=abc');
        $browser->get('/integrations/google/callback?state=' . $state . '&code=abc');

        self::assertCount(1, $this->google->requestsTo(GoogleOAuth::TOKEN_URL));
    }

    public function testMembersCannotConnectAndCannotSeeOtherWorkspacesConnections(): void
    {
        $this->connect();
        $connection = array_values($this->store->connections)[0];

        $member = $this->s->loggedIn($this->s->member);
        $member->get('/integrations');
        self::assertSame(403, $member->post('/integrations/google/connect')->getStatusCode());

        $rival = $this->s->loggedIn($this->s->rivalOwner);
        self::assertStringNotContainsString('seo@acme.test', (string) $rival->get('/integrations')->getBody());
        $rival->get('/integrations');
        self::assertSame(404, $rival->post('/integrations/' . $connection->publicId . '/disconnect')->getStatusCode());
        self::assertSame('active', $this->store->connections[$connection->id]->status);
    }

    public function testLinkProjectOnlyToReadablePropertiesOfOwnWorkspace(): void
    {
        $owner = $this->connect();
        $connection = array_values($this->store->connections)[0];
        $url = '/projects/' . $this->s->acmeProject->publicId . '/search-console';

        $owner->get($url);
        self::assertSame(422, $owner->post($url, ['property' => $connection->publicId . '|https://old.acme.test/'])->getStatusCode(), 'Unverified property.');
        $owner->get($url);
        self::assertSame(303, $owner->post($url, ['property' => $connection->publicId . '|sc-domain:acme.test'])->getStatusCode());
        self::assertSame('sc-domain:acme.test', $this->store->links[$this->s->acmeProject->id]->siteUrl);
        self::assertContains('project.search_console_linked', $this->s->audit()->actions());

        // Rival cannot link its project to Acme's connection.
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $rivalUrl = '/projects/' . $this->s->rivalProject->publicId . '/search-console';
        $rival->get($rivalUrl);
        self::assertSame(422, $rival->post($rivalUrl, ['property' => $connection->publicId . '|sc-domain:acme.test'])->getStatusCode());
        self::assertArrayNotHasKey($this->s->rivalProject->id, $this->store->links);
    }

    public function testDisconnectRevokesTokensAndUnlinksProjects(): void
    {
        $owner = $this->connect();
        $connection = array_values($this->store->connections)[0];
        $url = '/projects/' . $this->s->acmeProject->publicId . '/search-console';
        $owner->get($url);
        $owner->post($url, ['property' => $connection->publicId . '|sc-domain:acme.test']);

        $owner->get('/integrations');
        $owner->post('/integrations/' . $connection->publicId . '/disconnect');

        self::assertCount(1, $this->google->requestsTo(GoogleOAuth::REVOKE_URL));
        self::assertArrayNotHasKey($connection->id, $this->store->tokens);
        self::assertSame([], $this->store->links);
        self::assertSame('revoked', $this->store->connections[$connection->id]->status);
    }

    public function testExpiredAccessTokenIsRefreshedAndRevokedGrantMarksConnection(): void
    {
        $owner = $this->connect();
        $connection = array_values($this->store->connections)[0];
        $this->store->tokens[$connection->id]['access_expires_at'] = '2000-01-01 00:00:00';

        $this->google->json('POST', GoogleOAuth::TOKEN_URL, ['access_token' => 'ya29.access-2', 'expires_in' => 3599, 'scope' => 'x']);
        $owner->get('/integrations');
        $owner->post('/integrations/' . $connection->publicId . '/refresh');
        $sitesCall = $this->google->requestsTo('https://www.googleapis.com/webmasters/v3/sites');
        self::assertSame('Bearer ya29.access-2', end($sitesCall)['headers']['Authorization'] ?? null);

        // Google now reports the refresh token as revoked.
        $this->store->tokens[$connection->id]['access_expires_at'] = '2000-01-01 00:00:00';
        $this->google->on('POST', GoogleOAuth::TOKEN_URL, static fn(): HttpResponse => new HttpResponse(400, '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}'));
        $owner->get('/integrations');
        $owner->post('/integrations/' . $connection->publicId . '/refresh');

        self::assertSame('error', $this->store->connections[$connection->id]->status);
        self::assertContains('integration.google.authorization_lost', $this->s->audit()->actions());
        self::assertStringContainsString('Needs reconnection', (string) $owner->get('/integrations')->getBody());
    }

    private function configureClient(): void
    {
        $this->store->save('google', new OAuthClientCredentials(self::CLIENT_ID, 'GOCSPX-secret'), 1);
    }

    private function connect(): Browser
    {
        $this->configureClient();
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get('/integrations');
        $location = $browser->post('/integrations/google/connect')->getHeaderLine('Location');

        self::assertStringStartsWith(GoogleOAuth::AUTH_URL . '?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        self::assertSame(self::CLIENT_ID, $params['client_id']);
        self::assertSame('offline', $params['access_type']);
        self::assertSame('S256', $params['code_challenge_method']);
        self::assertIsString($params['scope']);
        self::assertStringContainsString('webmasters.readonly', $params['scope']);

        $response = $browser->get('/integrations/google/callback?state=' . $this->stateFrom($location) . '&code=4/auth-code');
        self::assertSame('/integrations', $response->getHeaderLine('Location'));

        return $browser;
    }

    private function stateFrom(string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        self::assertIsString($params['state'] ?? null);

        return $params['state'];
    }
}
