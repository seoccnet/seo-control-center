<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Workspace DataForSEO settings against a simulated DataForSEO API.
 */
final class DataForSeoSettingsTest extends TestCase
{
    private const string USER_DATA = 'https://api.dataforseo.com/v3/appendix/user_data';

    private Scenario $s;

    private FakeHttpClient $api;

    private InMemoryDataForSeo $store;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $http = $this->s->container->get(HttpClient::class);
        $store = $this->s->container->get(InMemoryDataForSeo::class);
        self::assertInstanceOf(FakeHttpClient::class, $http);
        self::assertInstanceOf(InMemoryDataForSeo::class, $store);
        $this->api = $http;
        $this->store = $store;

        // Accepts exactly one login/password pair, like the real API.
        $this->api->on('GET', self::USER_DATA, static function (string $url, array $headers): HttpResponse {
            if (($headers['Authorization'] ?? '') !== 'Basic ' . base64_encode('api@acme.test:right-password')) {
                return new HttpResponse(401, (string) json_encode(['status_code' => 40100, 'status_message' => 'You are not authorized.']));
            }

            return new HttpResponse(200, (string) json_encode(['status_code' => 20000, 'cost' => 0, 'tasks' => [
                ['status_code' => 20000, 'result' => [['login' => 'api@acme.test', 'money' => ['balance' => 42.5]]]],
            ]]));
        });
    }

    public function testOwnerConnectsAndPasswordIsNeverShown(): void
    {
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get('/integrations/dataforseo');

        $response = $browser->post('/integrations/dataforseo', ['login' => ' api@acme.test ', 'password' => 'right-password']);
        self::assertSame(303, $response->getStatusCode());

        $html = (string) $browser->get('/integrations/dataforseo')->getBody();
        self::assertStringContainsString('DataForSEO connected. Account balance: $42.5.', $html);
        self::assertStringContainsString('api@acme.test', $html);
        self::assertStringNotContainsString('right-password', $html);
        self::assertSame('right-password', $this->store->credentials($this->s->acme->id)?->password);
        self::assertContains('integration.dataforseo.connected', $this->s->audit()->actions());
        self::assertStringContainsString('no-store', $browser->get('/integrations/dataforseo')->getHeaderLine('Cache-Control'));
    }

    public function testWrongCredentialsAreRejectedAndNotStored(): void
    {
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get('/integrations/dataforseo');

        $response = $browser->post('/integrations/dataforseo', ['login' => 'api@acme.test', 'password' => 'wrong']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('DataForSEO rejected this API login or password.', (string) $response->getBody());
        self::assertNull($this->store->find($this->s->acme->id));
    }

    public function testEmptyInputIsValidatedWithoutCallingDataForSeo(): void
    {
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get('/integrations/dataforseo');

        self::assertSame(422, $browser->post('/integrations/dataforseo', ['login' => '', 'password' => ''])->getStatusCode());
        self::assertSame([], $this->api->requests);
    }

    public function testMembersAndViewersCannotManageButCanSeeUsage(): void
    {
        foreach ([$this->s->member, $this->s->viewer] as $user) {
            $browser = $this->s->loggedIn($user);
            $page = $browser->get('/integrations/dataforseo');
            self::assertSame(200, $page->getStatusCode());
            self::assertStringNotContainsString('name="password"', (string) $page->getBody());
            self::assertStringContainsString('This month (UTC)', (string) $page->getBody());
            $browser->get('/account/security'); // any page with a form, for a valid CSRF token
            $csrf = $browser->csrfToken();

            self::assertSame(403, $browser->post('/integrations/dataforseo', ['login' => 'api@acme.test', 'password' => 'right-password', '_csrf' => $csrf])->getStatusCode());
            self::assertSame(403, $browser->post('/integrations/dataforseo/budget', ['daily_limit' => '1', '_csrf' => $csrf])->getStatusCode());
            self::assertSame(403, $browser->post('/integrations/dataforseo/disconnect', ['_csrf' => $csrf])->getStatusCode());
        }
        self::assertNull($this->store->find($this->s->acme->id));
    }

    public function testBudgetsAreValidatedAndSaved(): void
    {
        $browser = $this->s->loggedIn($this->s->admin);
        $browser->get('/integrations/dataforseo');

        $invalid = $browser->post('/integrations/dataforseo/budget', ['daily_limit' => 'ten', 'monthly_limit' => '', 'operation_limit' => '']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Enter an amount in USD', (string) $invalid->getBody());

        self::assertSame(422, $browser->post('/integrations/dataforseo/budget', ['daily_limit' => '50', 'monthly_limit' => '20'])->getStatusCode());

        self::assertSame(303, $browser->post('/integrations/dataforseo/budget', ['daily_limit' => '2,50', 'monthly_limit' => '30', 'operation_limit' => ''])->getStatusCode());
        $budget = $this->store->budget($this->s->acme->id, DataForSeoClient::PROVIDER);
        self::assertSame(2.5, $budget->dailyLimit);
        self::assertSame(30.0, $budget->monthlyLimit);
        self::assertNull($budget->operationLimit);
        self::assertContains('integration.dataforseo.budget_updated', $this->s->audit()->actions());
        self::assertStringContainsString('budget $2.5', (string) $browser->get('/integrations/dataforseo')->getBody());
    }

    public function testMalformedBudgetFieldsCannotClearExistingLimits(): void
    {
        $browser = $this->s->loggedIn($this->s->admin);
        $browser->get('/integrations/dataforseo');
        self::assertSame(303, $browser->post('/integrations/dataforseo/budget', ['daily_limit' => '2.50', 'monthly_limit' => '30', 'operation_limit' => '1'])->getStatusCode());

        foreach (['daily_limit', 'monthly_limit', 'operation_limit'] as $field) {
            foreach ([[], ['100']] as $value) {
                $input = ['daily_limit' => '2.50', 'monthly_limit' => '30', 'operation_limit' => '1'];
                $input[$field] = $value;
                self::assertSame(422, $browser->post('/integrations/dataforseo/budget', $input)->getStatusCode());
                $budget = $this->store->budget($this->s->acme->id, DataForSeoClient::PROVIDER);
                self::assertSame(2.5, $budget->dailyLimit);
                self::assertSame(30.0, $budget->monthlyLimit);
                self::assertSame(1.0, $budget->operationLimit);
            }
        }
    }

    public function testVerifyDetectsRevokedPasswordAndDisconnectDeletesCredentials(): void
    {
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get('/integrations/dataforseo');
        $browser->post('/integrations/dataforseo', ['login' => 'api@acme.test', 'password' => 'right-password']);

        self::assertSame(303, $browser->post('/integrations/dataforseo/verify')->getStatusCode());
        self::assertStringContainsString('Connection works.', (string) $browser->get('/integrations/dataforseo')->getBody());

        // The password is changed in the DataForSEO dashboard.
        $this->store->save($this->s->acme->id, new \SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials('api@acme.test', 'old-password'), null, $this->s->owner->id);
        $browser->post('/integrations/dataforseo/verify');
        $html = (string) $browser->get('/integrations/dataforseo')->getBody();
        self::assertStringContainsString('Access refused', $html);
        self::assertSame('error', $this->store->find($this->s->acme->id)?->status);

        self::assertSame(303, $browser->post('/integrations/dataforseo/disconnect')->getStatusCode());
        self::assertNull($this->store->credentials($this->s->acme->id));
        self::assertContains('integration.dataforseo.disconnected', $this->s->audit()->actions());
    }

    public function testConnectionIsScopedToTheWorkspace(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get('/integrations/dataforseo');
        $owner->post('/integrations/dataforseo', ['login' => 'api@acme.test', 'password' => 'right-password']);

        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $html = (string) $rival->get('/integrations/dataforseo')->getBody();
        self::assertStringNotContainsString('api@acme.test', $html);
        self::assertNull($this->store->find($this->s->rival->id));

        // Disconnecting in the rival workspace cannot touch Acme's credentials.
        self::assertSame(409, $rival->post('/integrations/dataforseo/disconnect')->getStatusCode());
        self::assertNotNull($this->store->credentials($this->s->acme->id));
    }

    public function testIntegrationsPageLinksToDataForSeo(): void
    {
        $html = (string) $this->s->loggedIn($this->s->viewer)->get('/integrations')->getBody();
        self::assertStringContainsString('href="/integrations/dataforseo"', $html);
    }
}
