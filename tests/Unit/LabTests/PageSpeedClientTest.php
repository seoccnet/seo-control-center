<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\LabTests;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\LabTests\LabTestClients;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;

/** ADR-040, F2: the PageSpeed Insights request and its answers. */
final class PageSpeedClientTest extends TestCase
{
    public function testRunsTheRequestedStrategyWithTheKeyInAHeader(): void
    {
        $http = new FakeHttpClient();
        $http->json('GET', LabTestClients::PAGESPEED, ['lighthouseResult' => [
            'requestedUrl' => 'https://shop.test/', 'lighthouseVersion' => '12.3.0', 'configSettings' => ['formFactor' => 'desktop'],
            'categories' => ['performance' => ['score' => 0.98]],
            'audits' => ['largest-contentful-paint' => ['numericValue' => 900.0], 'cumulative-layout-shift' => ['numericValue' => 0.0]],
        ], 'loadingExperience' => ['metrics' => ['LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 5000]]]]);
        $result = (new LabTestClients($http))->pageSpeed('https://shop.test/', 'desktop', 'AIzaSySecretKey0123456789');

        self::assertSame([98, 900], [$result->present()['score'], $result->present()['lcp_ms']], 'Only the lab result is read; PageSpeed\'s field section is ignored.');
        $request = $http->requests[0];
        self::assertStringContainsString('strategy=desktop', $request['url']);
        self::assertStringContainsString('url=https%3A%2F%2Fshop.test%2F', $request['url']);
        self::assertStringNotContainsString('AIzaSySecretKey', $request['url'], 'The key never travels in the address (logs).');
        self::assertSame('AIzaSySecretKey0123456789', $request['headers']['X-Goog-Api-Key']);
    }

    public function testExplainsRefusalsWithoutEchoingTheProvider(): void
    {
        $http = new FakeHttpClient();
        $http->json('GET', LabTestClients::PAGESPEED, ['error' => ['code' => 403, 'message' => 'key AIzaSySecretKey0123456789 blocked', 'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'SERVICE_DISABLED']]]], 403);
        try {
            (new LabTestClients($http))->pageSpeed('https://shop.test/', 'mobile', 'AIzaSySecretKey0123456789');
            self::fail('A refusal throws.');
        } catch (IntegrationException $e) {
            self::assertStringContainsString('Enable the PageSpeed Insights API', $e->getMessage());
            self::assertStringNotContainsString('AIzaSy', $e->getMessage());
            self::assertFalse($e->retryable);
        }
        $http->json('GET', LabTestClients::PAGESPEED, ['error' => ['code' => 429]], 429);
        try {
            (new LabTestClients($http))->pageSpeed('https://shop.test/', 'mobile', 'AIzaSySecretKey0123456789');
            self::fail('A quota answer throws.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable, 'Quota exhaustion is retried later.');
        }
    }
}
