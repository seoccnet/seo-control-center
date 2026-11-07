<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Security;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Tests\Support\MemoryLogger;
use SeoControlCenter\Tests\Support\TestApp;
use SeoControlCenter\Tests\Support\ThrowingController;

/**
 * SECURITY.md / CODING_STANDARDS.md: never expose stack traces or internal
 * error messages to users in production.
 */
final class ErrorDisclosureTest extends TestCase
{
    public function testProductionErrorPageRevealsNothing(): void
    {
        $logger = new MemoryLogger();
        $container = TestApp::container(['APP_ENV' => 'production', 'APP_DEBUG' => 'true'], $logger);
        $container->instance(ThrowingController::class, new ThrowingController());
        $container->make(RouteCollection::class)->get('/boom', ThrowingController::class, 'boom', public: true);

        $response = TestApp::request($container, 'GET', '/boom');
        $body = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('hunter2', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('ThrowingController', $body);
        self::assertStringContainsString($response->getHeaderLine('X-Request-Id'), $body);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        // The failure is logged for diagnosis.
        self::assertSame('error', $logger->records[0]['level'] ?? null);
    }

    public function testProductionJsonErrorRevealsNothing(): void
    {
        $container = TestApp::container(['APP_ENV' => 'production']);
        $container->instance(ThrowingController::class, new ThrowingController());
        $container->make(RouteCollection::class)->get('/api/v1/boom', ThrowingController::class, 'boom', public: true);

        $response = TestApp::request($container, 'GET', '/api/v1/boom');
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertIsArray($data);
        self::assertArrayNotHasKey('debug', $data['error']);
        self::assertStringNotContainsString('hunter2', (string) $response->getBody());
    }

    public function testLocalDebugShowsDetailsForDevelopers(): void
    {
        $container = TestApp::container(['APP_ENV' => 'local', 'APP_DEBUG' => 'true']);
        $container->instance(ThrowingController::class, new ThrowingController());
        $container->make(RouteCollection::class)->get('/boom', ThrowingController::class, 'boom', public: true);

        $body = (string) TestApp::request($container, 'GET', '/boom')->getBody();

        self::assertStringContainsString('RuntimeException', $body);
    }
}
