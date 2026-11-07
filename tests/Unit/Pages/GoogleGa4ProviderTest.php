<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Pages;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\GoogleGa4Provider;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Ga4Fixture;

final class GoogleGa4ProviderTest extends TestCase
{
    public function testSafeErrorsIdentifyAuthenticationVersusReportWithoutLeakingResponse(): void
    {
        $credential = Ga4Fixture::credentials();
        foreach ([
            [true, 400, ['error' => 'invalid_grant', 'error_description' => 'private-secret'], 'INVALID_GRANT', false],
            [true, 401, ['error' => 'invalid_client'], 'INVALID_CLIENT', false],
            [true, 403, ['error' => 'unauthorized_client'], 'UNAUTHORIZED_CLIENT', false],
            [false, 403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'private-secret']], 'PERMISSION_DENIED', false],
            [false, 429, ['error' => ['status' => 'RESOURCE_EXHAUSTED']], 'RESOURCE_EXHAUSTED', true],
            [false, 403, ['error' => ['details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'domain' => 'googleapis.com', 'reason' => 'SERVICE_DISABLED', 'metadata' => ['secret' => 'private-secret']]]]], 'SERVICE_DISABLED', false],
            [false, 403, ['error' => ['details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'domain' => 'googleapis.com', 'reason' => 'SECURITY_POLICY_VIOLATED']]]], 'SECURITY_POLICY_VIOLATED', false],
            [false, 503, ['error' => ['details' => ['private-secret', ['@type' => 'other', 'domain' => 'googleapis.com', 'reason' => 'SERVICE_DISABLED']]]], 'Check the credential', true],
            [false, 403, ['error' => ['details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'domain' => 'other', 'reason' => 'SERVICE_DISABLED'], ['reason' => ['private-secret']]]]], 'Check the credential', false],
        ] as [$authentication, $status, $body, $hint, $retryable]) {
            $http = new FakeHttpClient();
            $http->json('POST', GoogleGa4Provider::TOKEN_ENDPOINT, ['access_token' => 'private-secret']);
            $http->json('POST', $authentication ? GoogleGa4Provider::TOKEN_ENDPOINT : GoogleGa4Provider::API . '123:runReport', $body, $status);
            $provider = new GoogleGa4Provider($http, new FrozenClock(new \DateTimeImmutable('2026-10-07 UTC')));
            try {
                $provider->report($credential, '123', 'acme.test', new DateRange('2026-09-01', '2026-09-28'));
                self::fail('Rejected requests must fail.');
            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                self::assertStringContainsString($authentication ? 'service-account authentication request' : 'GA4 report request', $e->getMessage());
                self::assertStringContainsString($hint, $e->getMessage());
                self::assertStringNotContainsString('private-secret', $e->getMessage());
                if ($retryable) {
                    self::assertTrue($e->retryable);
                } else {
                    self::assertFalse($e->retryable);
                }
                self::assertCount($authentication ? 1 : 2, $http->requests);
            }
        }
    }

    public function testTruncatedAndNonJsonErrorsStayGeneric(): void
    {
        $http = new FakeHttpClient();
        $http->json('POST', GoogleGa4Provider::TOKEN_ENDPOINT, ['access_token' => 'test-token']);
        $provider = new GoogleGa4Provider($http, new FrozenClock(new \DateTimeImmutable('2026-10-07 UTC')));
        foreach ([new HttpResponse(403, 'private-secret'), new HttpResponse(403, '{"error":{"status":"PERMISSION_DENIED"}}', truncated: true)] as $response) {
            $http->on('POST', GoogleGa4Provider::API . '123:runReport', static fn(): HttpResponse => $response);
            try {
                $provider->report(Ga4Fixture::credentials(), '123', 'acme.test', new DateRange('2026-09-01', '2026-09-28'));
                self::fail('Invalid responses must fail.');
            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                self::assertStringContainsString('Check the credential, property access and API quota.', $e->getMessage());
                self::assertStringNotContainsString('private-secret', $e->getMessage());
                self::assertSame($response->truncated, $e->retryable);
            }
        }
    }

    public function testUploadedCredentialsRejectFileReferencesBeforeCallingOpenSsl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \SeoControlCenter\Domain\Analytics\Ga4Credentials::parse(json_encode(['type' => 'service_account', 'client_email' => 'test@example.iam.gserviceaccount.com', 'private_key' => 'file:///test-only-do-not-open.pem'], JSON_THROW_ON_ERROR));
    }
    public function testSignedReadOnlyAccessAndOrganicLandingPageNormalization(): void
    {
        $http = new FakeHttpClient();
        $credential = Ga4Fixture::credentials();
        $http->on('POST', GoogleGa4Provider::TOKEN_ENDPOINT, static function (string $url, array $headers, ?string $body) use ($credential): HttpResponse {
            parse_str((string) $body, $params);
            self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $params['grant_type']);
            self::assertIsString($params['assertion']);
            $parts = explode('.', $params['assertion']);
            $decode = static fn(string $s): string => (string) base64_decode(strtr($s, '-_', '+/'));
            $claims = json_decode($decode($parts[1]), true);
            self::assertSame('https://www.googleapis.com/auth/analytics.readonly', $claims['scope']);
            self::assertSame(GoogleGa4Provider::TOKEN_ENDPOINT, $claims['aud']);
            self::assertSame(3600, $claims['exp'] - $claims['iat']);
            $key = openssl_pkey_get_private($credential->privateKey);
            self::assertNotFalse($key);
            $details = openssl_pkey_get_details($key);
            self::assertNotFalse($details);
            self::assertSame(1, openssl_verify($parts[0] . '.' . $parts[1], $decode($parts[2]), $details['key'], OPENSSL_ALGO_SHA256));
            return new HttpResponse(200, '{"access_token":"test-token"}');
        });
        $http->json('POST', GoogleGa4Provider::API . '123:runReport', ['dimensionHeaders' => array_map(static fn(string $name): array => ['name' => $name], ['date', 'hostName', 'landingPagePlusQueryString']), 'metricHeaders' => array_map(static fn(string $name): array => ['name' => $name], ['sessions', 'keyEvents', 'purchaseRevenue']), 'rowCount' => 10001, 'metadata' => ['currencyCode' => 'EUR', 'timeZone' => 'Europe/Berlin', 'subjectToThresholding' => true], 'rows' => [
            ['dimensionValues' => [['value' => '20261001'], ['value' => 'www.acme.test'], ['value' => '/pricing?plan=pro']], 'metricValues' => [['value' => '10'], ['value' => '2.5'], ['value' => '99.95']]],
            ['dimensionValues' => [['value' => '20261001'], ['value' => 'foreign.test'], ['value' => '/private']], 'metricValues' => [['value' => '999'], ['value' => '999'], ['value' => '999']]],
        ]]);
        $provider = new GoogleGa4Provider($http, new FrozenClock(new \DateTimeImmutable('2026-10-04 12:00:00 UTC')));
        $report = $provider->report($credential, '123', 'acme.test', new DateRange('2026-09-01', '2026-10-03'));
        self::assertSame(['EUR', true, true], [$report->currency, $report->limited, $report->thresholded]);
        self::assertCount(1, $report->rows);
        self::assertSame(['https://www.acme.test/pricing?plan=pro', 10, 2.5, 99.95], array_values($report->rows[0]->toArray()));
        $request = json_decode((string) $http->requests[1]['body'], true);
        self::assertSame('Organic Search', $request['dimensionFilter']['andGroup']['expressions'][0]['filter']['stringFilter']['value']);
        self::assertSame(['sessions', 'keyEvents', 'purchaseRevenue'], array_column($request['metrics'], 'name'));
    }
}
