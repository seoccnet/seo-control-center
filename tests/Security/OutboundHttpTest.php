<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\CurlHttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleErrors;

/**
 * Provider adapters may only reach the hosts they declare, over HTTPS (SECURITY.md -> SSRF).
 */
final class OutboundHttpTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function blocked(): iterable
    {
        yield 'plain http' => ['http://www.googleapis.com/webmasters/v3/sites'];
        yield 'other host' => ['https://evil.example/steal'];
        yield 'metadata endpoint' => ['https://169.254.169.254/latest/meta-data'];
        yield 'userinfo trick' => ['https://www.googleapis.com@evil.example/'];
        yield 'explicit port' => ['https://www.googleapis.com:8443/'];
        yield 'lookalike host' => ['https://www.googleapis.com.evil.example/'];
    }

    #[DataProvider('blocked')]
    public function testDisallowedDestinationsAreRejectedBeforeConnecting(string $url): void
    {
        try {
            (new CurlHttpClient(2))->send('GET', $url, [], null, ['www.googleapis.com']);
            self::fail('Expected rejection');
        } catch (HttpClientException $e) {
            self::assertFalse($e->retryable);
            self::assertStringContainsString('not allowed', $e->getMessage());
        }
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $this->expectException(HttpClientException::class);
        (new CurlHttpClient(2))->send('GET', 'https://www.googleapis.com/', ['X-Test' => "a\r\nHost: evil"], null, ['www.googleapis.com']);
    }

    public function testGoogleErrorClassification(): void
    {
        $cases = [
            [new HttpResponse(400, '{"error":"invalid_grant"}'), false, true],
            [new HttpResponse(401, '{"error":{"status":"UNAUTHENTICATED","message":"x"}}'), false, true],
            [new HttpResponse(429, '{"error":{"message":"Quota exceeded"}}', 30), true, false],
            [new HttpResponse(503, 'Service Unavailable'), true, false],
            [new HttpResponse(403, '{"error":{"message":"User does not have sufficient permission"}}'), false, false],
        ];
        foreach ($cases as [$response, $retryable, $lost]) {
            try {
                GoogleErrors::check($response);
                self::fail('Expected exception for ' . $response->status);
            } catch (IntegrationException $e) {
                self::assertSame($retryable, $e->retryable, (string) $response->status);
                self::assertSame($lost, $e->authorizationLost, (string) $response->status);
            }
        }
        self::assertSame(200, GoogleErrors::check(new HttpResponse(200, '{}'))->status);
    }
}
