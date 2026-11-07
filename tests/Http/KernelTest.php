<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\TestApp;

final class KernelTest extends TestCase
{
    public function testGuestsAreRedirectedToLogin(): void
    {
        $response = TestApp::request(TestApp::container(), 'GET', '/');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testLoginPageRenders(): void
    {
        $response = TestApp::request(TestApp::container(), 'GET', '/login');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Sign in', (string) $response->getBody());
    }

    public function testApiClientsGet401InsteadOfRedirect(): void
    {
        $response = TestApp::request(TestApp::container(), 'GET', '/projects', ['Accept' => 'application/json']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testLivenessProbeRevealsNothing(): void
    {
        $response = TestApp::request(TestApp::container(), 'GET', '/health/live');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
    }

    public function testUnknownRouteIs404Html(): void
    {
        $response = TestApp::request(TestApp::container(), 'GET', '/nope');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Error 404', (string) $response->getBody());
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        // OPTIONS is a safe method, so CSRF does not intervene before routing.
        $response = TestApp::request(TestApp::container(), 'OPTIONS', '/');

        self::assertSame(405, $response->getStatusCode());
        self::assertStringContainsString('GET', $response->getHeaderLine('Allow'));
    }

    public function testJsonClientsGetJsonErrors(): void
    {
        $response = TestApp::request(TestApp::container(), 'GET', '/nope', ['Accept' => 'application/json']);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testEveryResponseHasRequestId(): void
    {
        $response = TestApp::request(TestApp::container(), 'GET', '/nope');

        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $response->getHeaderLine('X-Request-Id'));
    }
}
