<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Security\Totp;
use SeoControlCenter\Domain\Accounts\MfaService;
use SeoControlCenter\Tests\Support\Browser;
use SeoControlCenter\Tests\Support\Scenario;

final class MfaFlowTest extends TestCase
{
    public function testEnableThenLoginRequiresSecondFactor(): void
    {
        $s = new Scenario();
        [$secret, $codes] = $this->enable($s);

        $browser = $s->browser();
        $response = $browser->login('owner@acme.test', Scenario::PASSWORD);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login/verify', $response->getHeaderLine('Location'));

        // Not signed in yet.
        self::assertSame(303, $browser->get('/')->getStatusCode());

        $browser->get('/login/verify');
        self::assertSame(422, $browser->post('/login/verify', ['code' => '000000'])->getStatusCode());

        $browser->get('/login/verify');
        $ok = $browser->post('/login/verify', ['code' => $this->nextCode($secret)]);
        self::assertSame(303, $ok->getStatusCode());
        self::assertSame('/', $ok->getHeaderLine('Location'));
        self::assertSame(200, $browser->get('/')->getStatusCode());
        self::assertCount(10, $codes);
        self::assertContains('mfa.enabled', $s->audit()->actions());
    }

    public function testRecoveryCodeWorksExactlyOnce(): void
    {
        $s = new Scenario();
        [, $codes] = $this->enable($s);

        $first = $this->passwordStep($s);
        self::assertSame(303, $first->post('/login/verify', ['code' => strtoupper($codes[0])])->getStatusCode());
        self::assertContains('mfa.recovery_code_used', $s->audit()->actions());

        $second = $this->passwordStep($s);
        self::assertSame(422, $second->post('/login/verify', ['code' => $codes[0]])->getStatusCode());
    }

    public function testVerifyStepIsThrottled(): void
    {
        $s = new Scenario();
        $this->enable($s);
        $browser = $this->passwordStep($s);

        for ($i = 0; $i < MfaService::MAX_ATTEMPTS; ++$i) {
            $browser->get('/login/verify');
            self::assertSame(422, $browser->post('/login/verify', ['code' => sprintf('%06d', $i)])->getStatusCode());
        }
        $browser->get('/login/verify');
        self::assertSame(429, $browser->post('/login/verify', ['code' => '123456'])->getStatusCode());
    }

    public function testVerifyPageWithoutPasswordStepRedirects(): void
    {
        $s = new Scenario();
        $browser = $s->browser();

        self::assertSame('/login', $browser->get('/login/verify')->getHeaderLine('Location'));
    }

    public function testSetupRejectsWrongCodeAndSecretIsEncryptedInSession(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/account/security');
        $page = (string) $browser->post('/account/security/mfa/setup')->getBody();
        self::assertStringContainsString('Set up two-factor authentication', $page);

        $key = $this->extractKey($page);
        foreach ($s->container->get(\SeoControlCenter\Core\Session\SessionStore::class)->sessions as $row) {
            self::assertStringNotContainsString(Totp::base32Encode(Totp::base32Decode($key)), json_encode($row['data'], JSON_THROW_ON_ERROR));
        }

        self::assertSame(422, $browser->post('/account/security/mfa/confirm', ['code' => '000000'])->getStatusCode());
        self::assertNotContains('mfa.enabled', $s->audit()->actions());
    }

    public function testDisableRequiresPasswordAndCode(): void
    {
        $s = new Scenario();
        [$secret, , $browser] = $this->enable($s);

        $browser->get('/account/security');
        $browser->post('/account/security/mfa/disable', ['password' => 'wrong', 'code' => $this->nextCode($secret)]);
        self::assertNotContains('mfa.disabled', $s->audit()->actions());

        $browser->get('/account/security');
        // The code was not consumed by the rejected attempt (password is checked first).
        $browser->post('/account/security/mfa/disable', ['password' => Scenario::PASSWORD, 'code' => $this->nextCode($secret)]);
        self::assertContains('mfa.disabled', $s->audit()->actions());

        self::assertSame('/', $s->browser()->login('owner@acme.test', Scenario::PASSWORD)->getHeaderLine('Location'));
    }

    public function testSignOutOtherSessions(): void
    {
        $s = new Scenario();
        $laptop = $s->loggedIn($s->owner);
        $phone = $s->loggedIn($s->owner);

        $laptop->get('/account/security');
        $laptop->post('/account/sessions/logout-others');

        self::assertSame(200, $laptop->get('/')->getStatusCode());
        self::assertSame(303, $phone->get('/')->getStatusCode());
    }

    /**
     * @return array{0: string, 1: list<string>, 2: Browser}
     */
    private function enable(Scenario $s): array
    {
        $browser = $s->loggedIn($s->owner);
        $browser->get('/account/security');
        $page = (string) $browser->post('/account/security/mfa/setup')->getBody();
        $secret = Totp::base32Decode($this->extractKey($page));

        $result = $browser->post('/account/security/mfa/confirm', ['code' => Totp::code($secret, Totp::stepAt(time()))]);
        self::assertSame(200, $result->getStatusCode());
        preg_match_all('#<li><code>([a-z0-9]{5}-[a-z0-9]{5})</code></li>#', (string) $result->getBody(), $m);

        return [$secret, $m[1], $browser];
    }

    private function passwordStep(Scenario $s): Browser
    {
        $browser = $s->browser();
        self::assertSame('/login/verify', $browser->login('owner@acme.test', Scenario::PASSWORD)->getHeaderLine('Location'));
        $browser->get('/login/verify');

        return $browser;
    }

    /**
     * A code for a step after the one used during setup (replay protection).
     */
    private function nextCode(string $secret, int $offset = 0): string
    {
        return Totp::code($secret, Totp::stepAt(time()) + 1 + $offset);
    }

    private function extractKey(string $html): string
    {
        if (preg_match('#<p class="setup-key"><code>([A-Z2-7 ]+)</code></p>#', $html, $m) !== 1) {
            self::fail('Setup key not found');
        }

        return str_replace(' ', '', $m[1]);
    }
}
