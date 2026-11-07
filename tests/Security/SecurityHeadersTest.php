<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Security;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\TestApp;

final class SecurityHeadersTest extends TestCase
{
    public function testHeadersArePresentOnNormalAndErrorResponses(): void
    {
        $container = TestApp::container();

        foreach (['/', '/does-not-exist'] as $path) {
            $response = TestApp::request($container, 'GET', $path);
            $csp = $response->getHeaderLine('Content-Security-Policy');

            self::assertStringContainsString("default-src 'self'", $csp, $path);
            self::assertStringContainsString("frame-ancestors 'none'", $csp, $path);
            self::assertStringContainsString("object-src 'none'", $csp, $path);
            self::assertStringNotContainsString('unsafe-inline', $csp, $path);
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), $path);
            self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'), $path);
            self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'), $path);
            self::assertNotSame('', $response->getHeaderLine('Permissions-Policy'), $path);
        }
    }

    public function testFormsMayOnlyPostToSelfOrTheOAuthProvider(): void
    {
        // Browsers apply form-action to the redirect after a POST, so the Google
        // "connect" form needs accounts.google.com and the MCP sign-in (ADR-029)
        // the AI app hosts — and nothing else.
        $csp = TestApp::request(TestApp::container(), 'GET', '/')->getHeaderLine('Content-Security-Policy');

        self::assertStringContainsString("form-action 'self' https://accounts.google.com https://chatgpt.com https://claude.ai https://claude.com;", $csp);
    }

    public function testCspNonceIsUniquePerRequest(): void
    {
        $container = TestApp::container();
        $a = TestApp::request($container, 'GET', '/')->getHeaderLine('Content-Security-Policy');
        $b = TestApp::request($container, 'GET', '/')->getHeaderLine('Content-Security-Policy');

        self::assertMatchesRegularExpression("/'nonce-[A-Za-z0-9+\\/=]{24}'/", $a);
        self::assertNotSame($a, $b);
    }

    public function testHstsOnlyOnHttpsWhenEnabled(): void
    {
        $container = TestApp::container(['SECURITY_HSTS' => 'true']);

        self::assertSame('', TestApp::request($container, 'GET', 'http://example.test/')->getHeaderLine('Strict-Transport-Security'));
        self::assertStringStartsWith('max-age=', TestApp::request($container, 'GET', 'https://example.test/')->getHeaderLine('Strict-Transport-Security'));
    }

    public function testClientSuppliedRequestIdIsIgnored(): void
    {
        $container = TestApp::container();
        $response = TestApp::request($container, 'GET', '/', ['X-Request-Id' => 'client-chosen-id']);

        self::assertNotSame('client-chosen-id', $response->getHeaderLine('X-Request-Id'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $response->getHeaderLine('X-Request-Id'));
    }
}
