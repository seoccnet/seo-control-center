<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

final class AuthFlowTest extends TestCase
{
    public function testSuccessfulLoginShowsDashboard(): void
    {
        $s = new Scenario();
        $browser = $s->browser();

        $response = $browser->login('Owner@Acme.test', Scenario::PASSWORD);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));

        $dashboard = $browser->get('/');
        $html = (string) $dashboard->getBody();
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('Dashboard', $html);
        self::assertStringContainsString('Acme', $html);
        self::assertStringContainsString('Acme Shop', $html);
        self::assertContains('auth.login', $s->audit()->actions());
    }

    public function testWrongPasswordAndUnknownUserGetTheSameAnswer(): void
    {
        $s = new Scenario();

        $wrong = $s->browser()->login('owner@acme.test', 'wrong password!!');
        $unknown = $s->browser()->login('nobody@acme.test', 'wrong password!!');

        self::assertSame(422, $wrong->getStatusCode());
        self::assertSame(422, $unknown->getStatusCode());
        self::assertStringContainsString('The e-mail address or password is incorrect.', (string) $wrong->getBody());
        self::assertStringContainsString('The e-mail address or password is incorrect.', (string) $unknown->getBody());
        self::assertSame(['auth.login_failed', 'auth.login_failed'], $s->audit()->actions());
    }

    public function testDisabledUserCannotSignIn(): void
    {
        $s = new Scenario();
        $s->addUser('gone@acme.test', active: false);

        self::assertSame(422, $s->browser()->login('gone@acme.test', Scenario::PASSWORD)->getStatusCode());
    }

    public function testSignedInUserVisitingLoginIsRedirected(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);

        self::assertSame(303, $browser->get('/login')->getStatusCode());
    }

    public function testLogoutEndsTheSession(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/');

        $response = $browser->post('/logout');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertSame(303, $browser->get('/')->getStatusCode(), 'Dashboard requires login again.');
        self::assertContains('auth.logout', $s->audit()->actions());
    }

    public function testUserWithoutWorkspaceSeesExplanation(): void
    {
        $s = new Scenario();
        $s->addUser('lonely@acme.test');
        $browser = $s->loggedIn($s->dir->findByEmail(new \SeoControlCenter\Domain\Accounts\Email('lonely@acme.test')) ?? throw new \LogicException());

        self::assertStringContainsString('No workspace yet', (string) $browser->get('/')->getBody());
        self::assertSame(403, $browser->get('/projects')->getStatusCode());
    }
}
