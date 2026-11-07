<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Pages;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\CoreWebVitalsRule;
use SeoControlCenter\Modules\WebVitals\CruxProvider;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;

final class CruxProviderTest extends TestCase
{
    public function testGoogleRestrictionReasonsAreSafeAndActionable(): void
    {
        $http = new FakeHttpClient();
        $provider = new CruxProvider($http, new FrozenClock(new \DateTimeImmutable('2026-10-07 UTC')));
        foreach (['API_KEY_IP_ADDRESS_BLOCKED', 'API_KEY_HTTP_REFERRER_BLOCKED', 'API_KEY_SERVICE_BLOCKED', 'SERVICE_DISABLED', 'API_KEY_INVALID', 'API_KEY_EXPIRED', 'API_KEY_NOT_FOUND', 'CONSUMER_INVALID'] as $reason) {
            $http->json('POST', CruxProvider::ENDPOINT, ['error' => ['message' => 'private_key', 'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'domain' => 'googleapis.com', 'reason' => $reason, 'metadata' => ['key' => 'private_key']],
            ]]], 403);
            try {
                $provider->fetch('https://acme.test/page', 'private_key');
                self::fail('Rejected requests must fail.');
            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                self::assertStringContainsString($reason . ':', $e->getMessage());
                self::assertStringNotContainsString('private_key', $e->getMessage());
                self::assertFalse($e->retryable);
            }
        }
    }

    public function testUntrustedOrMalformedErrorsKeepGenericFailureAndRetryPolicy(): void
    {
        $http = new FakeHttpClient();
        $provider = new CruxProvider($http, new FrozenClock(new \DateTimeImmutable('2026-10-07 UTC')));
        $detail = ['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'domain' => 'googleapis.com', 'reason' => 'API_KEY_IP_ADDRESS_BLOCKED'];
        foreach ([
            new HttpResponse(403, 'not JSON'),
            new HttpResponse(403, '{"error":"private_key"}'),
            new HttpResponse(403, json_encode(['error' => ['details' => [$detail]]], JSON_THROW_ON_ERROR), truncated: true),
            new HttpResponse(403, json_encode(['error' => ['details' => [[...$detail, 'domain' => 'other.test']]]], JSON_THROW_ON_ERROR)),
            new HttpResponse(403, json_encode(['error' => ['details' => [[...$detail, '@type' => 'other']]]], JSON_THROW_ON_ERROR)),
            new HttpResponse(403, json_encode(['error' => ['details' => ['private_key', [...$detail, 'reason' => ['private_key']], [...$detail, 'reason' => 'private_key']]]], JSON_THROW_ON_ERROR)),
            new HttpResponse(429, '{}'),
            new HttpResponse(503, '{}'),
        ] as $response) {
            $http->on('POST', CruxProvider::ENDPOINT, static fn(): HttpResponse => $response);
            try {
                $provider->fetch('https://acme.test/page', 'private_key');
                self::fail('Rejected requests must fail.');
            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                self::assertSame('Chrome UX Report refused the request (HTTP ' . $response->status . '). Check the API key and quota.', $e->getMessage());
                self::assertSame($response->status === 429 || $response->status >= 500, $e->retryable);
            }
        }
    }

    public function testInvalidIdentityDatesAndEmptyMetricsAreRejected(): void
    {
        $record = ['key' => ['url' => 'https://acme.test/page', 'formFactor' => 'PHONE'],
            'metrics' => ['largest_contentful_paint' => ['percentiles' => ['p75' => 2800]]],
            'collectionPeriod' => ['firstDate' => ['year' => 2026, 'month' => 9, 'day' => 3], 'lastDate' => ['year' => 2026, 'month' => 9, 'day' => 30]]];
        $http = new FakeHttpClient();
        $provider = new CruxProvider($http, new FrozenClock(new \DateTimeImmutable('2026-10-04 UTC')));
        foreach ([
            [...$record, 'key' => ['origin' => 'https://acme.test', 'formFactor' => 'PHONE']],
            [...$record, 'key' => ['url' => 'https://acme.test/other', 'formFactor' => 'PHONE']],
            [...$record, 'key' => ['url' => 'https://acme.test/page', 'formFactor' => 'DESKTOP']],
            [...$record, 'collectionPeriod' => ['firstDate' => ['year' => 2026, 'month' => 10, 'day' => 5], 'lastDate' => ['year' => 2026, 'month' => 10, 'day' => 6]]],
            [...$record, 'metrics' => []],
        ] as $invalid) {
            $http->json('POST', CruxProvider::ENDPOINT, ['record' => $invalid]);
            try {
                $provider->fetch('https://acme.test/page', 'fake_key');
                self::fail('Invalid field data must not be published.');
            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                self::assertTrue($e->retryable);
            }
        }
        $http->json('POST', CruxProvider::ENDPOINT, ['record' => $record,
            'urlNormalizationDetails' => ['originalUrl' => 'https://acme.test/page?ref=x', 'normalizedUrl' => 'https://acme.test/page']]);
        self::assertSame('https://acme.test/page?ref=x', $provider->fetch('https://acme.test/page?ref=x', 'fake_key')->url);
    }
    public function testDesktopRequestAndMissingCoverageRetainDevice(): void
    {
        $http = new FakeHttpClient();
        $http->on('POST', CruxProvider::ENDPOINT, static fn(): HttpResponse => new HttpResponse(404, '{}'));
        $provider = new CruxProvider($http, new FrozenClock(new \DateTimeImmutable('2026-10-07 UTC')));
        $record = $provider->fetch('https://acme.test/page', 'fake_key', 'DESKTOP');
        self::assertSame('DESKTOP', $record->formFactor);
        self::assertSame('DESKTOP', $record->toArray()['form_factor']);
        self::assertSame('no_data', $record->status);
        self::assertSame('DESKTOP', json_decode((string) $http->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR)['formFactor']);
    }

    public function testFieldValuesAndMissingCoverageAreKeptDistinct(): void
    {
        $http = new FakeHttpClient();
        $http->json('POST', CruxProvider::ENDPOINT, ['record' => ['key' => ['url' => 'https://acme.test/page', 'formFactor' => 'PHONE'], 'metrics' => ['largest_contentful_paint' => ['percentiles' => ['p75' => 4100]], 'interaction_to_next_paint' => ['percentiles' => ['p75' => 150]], 'cumulative_layout_shift' => ['percentiles' => ['p75' => '0.26']]],
            'collectionPeriod' => ['firstDate' => ['year' => 2026, 'month' => 9, 'day' => 3], 'lastDate' => ['year' => 2026, 'month' => 9, 'day' => 30]]]]);
        $provider = new CruxProvider($http, new FrozenClock(new \DateTimeImmutable('2026-10-04 12:00:00 UTC')));
        $record = $provider->fetch('https://acme.test/page', 'test_key');
        self::assertSame([4100.0, 150.0, 0.26, '2026-09-30'], [$record->lcp, $record->inp, $record->cls, $record->periodTo]);
        self::assertSame(['LCP', 'CLS'], $record->needsWork());
        self::assertCount(1, (new CoreWebVitalsRule())->evaluate(new ProjectSignals(webVitals: [$record])));
        $body = json_decode((string) $http->requests[0]['body'], true);
        self::assertSame('PHONE', $body['formFactor']);
        self::assertArrayNotHasKey('origin', $body, 'Origin data is never substituted for page data.');
        $http->on('POST', CruxProvider::ENDPOINT, static fn(): HttpResponse => new HttpResponse(404, '{}'));
        $missing = $provider->fetch('https://acme.test/quiet', 'test_key');
        self::assertSame('no_data', $missing->status);
        self::assertNull($missing->lcp);
        self::assertSame([], (new CoreWebVitalsRule())->evaluate(new ProjectSignals(webVitals: [$missing])));
    }
}
