<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Security;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Domain\Accounts\AuthenticationService;
use SeoControlCenter\Tests\Support\Scenario;

final class SessionSecurityTest extends TestCase
{
    public function testSessionIdChangesOnLogin(): void
    {
        $s = new Scenario();
        $kernel = $s->container->make(Kernel::class);

        $page = $kernel->handle(new ServerRequest('GET', 'http://scc.test/login'));
        $guestCookie = $this->cookieValue($page->getHeaderLine('Set-Cookie'));
        self::assertSame(1, preg_match('/name="_csrf" value="([0-9a-f]{64})"/', (string) $page->getBody(), $m));

        $login = $kernel->handle((new ServerRequest('POST', 'http://scc.test/login'))
            ->withCookieParams(['scc_session' => $guestCookie])
            ->withParsedBody(['_csrf' => $m[1], 'email' => 'owner@acme.test', 'password' => Scenario::PASSWORD]));
        $userCookie = $this->cookieValue($login->getHeaderLine('Set-Cookie'));

        self::assertSame(303, $login->getStatusCode());
        self::assertNotSame($guestCookie, $userCookie, 'Session fixation: the ID must change on login.');

        // The old (pre-login) session ID must not grant access.
        $withOld = $kernel->handle((new ServerRequest('GET', 'http://scc.test/'))->withCookieParams(['scc_session' => $guestCookie]));
        self::assertSame(303, $withOld->getStatusCode());
    }

    public function testCookieFlags(): void
    {
        $page = (new Scenario())->container->make(Kernel::class)->handle(new ServerRequest('GET', 'http://scc.test/login'));
        $cookie = $page->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
        self::assertStringContainsString('Path=/', $cookie);
    }

    public function testHttpsDeploymentsUseSecureHostPrefixedCookie(): void
    {
        $s = new Scenario(['APP_URL' => 'https://scc.example.com']);
        $page = $s->container->make(Kernel::class)->handle(new ServerRequest('GET', 'https://scc.example.com/login'));
        $cookie = $page->getHeaderLine('Set-Cookie');

        self::assertStringStartsWith('__Host-scc_session=', $cookie);
        self::assertStringContainsString('Secure', $cookie);
    }

    public function testGuestPagesWithoutFormsDoNotCreateSessions(): void
    {
        $response = (new Scenario())->container->make(Kernel::class)->handle(new ServerRequest('GET', 'http://scc.test/health/live'));

        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function testLoginIsThrottled(): void
    {
        $s = new Scenario();
        $browser = $s->browser();
        for ($i = 0; $i < AuthenticationService::MAX_PER_ACCOUNT_AND_IP; ++$i) {
            self::assertSame(422, $browser->login('owner@acme.test', 'wrong password ' . $i)->getStatusCode());
        }

        // Even the correct password is refused while throttled.
        $blocked = $browser->login('owner@acme.test', Scenario::PASSWORD);
        self::assertSame(429, $blocked->getStatusCode());
        self::assertNotSame('', $blocked->getHeaderLine('Retry-After'));
        self::assertContains('auth.login_throttled', $s->audit()->actions());

        // Another account from the same IP is not locked by this.
        self::assertSame(303, $s->browser()->login('admin@acme.test', Scenario::PASSWORD)->getStatusCode());
    }

    public function testRequestsFromDisabledAccountsAreSignedOut(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->member);
        self::assertSame(200, $browser->get('/')->getStatusCode());

        $row = $s->dir->users[$s->member->id];
        $u = $row['user'];
        $s->dir->users[$s->member->id]['user'] = new \SeoControlCenter\Domain\Accounts\User($u->id, $u->publicId, $u->email, $u->name, $u->systemRole, false);

        self::assertSame(303, $browser->get('/')->getStatusCode());
    }

    private function cookieValue(string $header): string
    {
        if (preg_match('/^scc_session=([0-9a-f]{64});/', $header, $m) !== 1) {
            self::fail('No session cookie in: ' . $header);
        }

        return $m[1];
    }
}
