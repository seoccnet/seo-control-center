<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Security;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * TESTING.md -> Authentication/session: CSRF.
 */
final class CsrfTest extends TestCase
{
    public function testLoginWithoutTokenIsRejected(): void
    {
        $s = new Scenario();
        $browser = $s->browser();
        $browser->get('/login');

        $response = $browser->post('/login', ['email' => 'owner@acme.test', 'password' => Scenario::PASSWORD], withCsrf: false);

        self::assertSame(419, $response->getStatusCode());
        self::assertNotContains('auth.login', $s->audit()->actions());
    }

    public function testWrongTokenIsRejected(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/projects/new');

        $response = $browser->post('/projects', ['_csrf' => str_repeat('0', 64), 'name' => 'X', 'primary_domain' => 'x.test']);

        self::assertSame(419, $response->getStatusCode());
        self::assertNotContains('project.created', $s->audit()->actions());
    }

    public function testTokenInHeaderIsAccepted(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/projects/new');
        $token = $browser->csrfToken();

        $response = $browser->post('/projects', ['name' => 'Header', 'primary_domain' => 'header.test'], ['X-CSRF-Token' => $token], withCsrf: false);

        self::assertSame(303, $response->getStatusCode());
    }

    public function testForeignOriginIsRejectedEvenWithValidToken(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/projects/new');

        $response = $browser->post('/projects', ['name' => 'X', 'primary_domain' => 'x.test'], ['Origin' => 'https://evil.example']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testSameOriginIsAccepted(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/projects/new');

        $response = $browser->post('/projects', ['name' => 'X', 'primary_domain' => 'x.test'], ['Origin' => 'http://scc.test']);

        self::assertSame(303, $response->getStatusCode());
    }

    public function testTokenFromAnotherSessionIsRejected(): void
    {
        $s = new Scenario();
        $attacker = $s->loggedIn($s->rivalOwner);
        $attacker->get('/projects/new');
        $foreignToken = $attacker->csrfToken();

        $victim = $s->loggedIn($s->owner);
        $victim->get('/');
        $response = $victim->post('/projects', ['_csrf' => $foreignToken, 'name' => 'X', 'primary_domain' => 'x.test']);

        self::assertSame(419, $response->getStatusCode());
    }
}
