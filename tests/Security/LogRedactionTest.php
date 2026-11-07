<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Logging\Redactor;

/**
 * TESTING.md -> Logging: verify secrets are redacted.
 */
final class LogRedactionTest extends TestCase
{
    public function testRedactsSensitiveKeysAtAnyDepth(): void
    {
        $redacted = (new Redactor(['dataforseo_login']))->redactArray([
            'password' => 'hunter2',
            'user' => 'alice',
            'headers' => ['Authorization' => 'Bearer abc', 'Cookie' => 'scc_session=xyz', 'Accept' => 'text/html'],
            'oauth' => ['refresh_token' => 'rt', 'access_token' => 'at', 'expires_in' => 3600],
            'google_client_secret' => 's',
            'dataforseo_login' => 'me@example.com',
            'list' => [['api_key' => 'k']],
        ]);

        self::assertSame(Redactor::MASK, $redacted['password']);
        self::assertSame('alice', $redacted['user']);
        self::assertSame(Redactor::MASK, $redacted['headers']['Authorization']);
        self::assertSame(Redactor::MASK, $redacted['headers']['Cookie']);
        self::assertSame('text/html', $redacted['headers']['Accept']);
        self::assertSame(Redactor::MASK, $redacted['oauth']['refresh_token']);
        self::assertSame(Redactor::MASK, $redacted['oauth']['access_token']);
        self::assertSame(3600, $redacted['oauth']['expires_in']);
        self::assertSame(Redactor::MASK, $redacted['google_client_secret']);
        self::assertSame(Redactor::MASK, $redacted['dataforseo_login']);
        self::assertSame(Redactor::MASK, $redacted['list'][0]['api_key']);
    }

    #[DataProvider('secretStrings')]
    public function testRedactsSecretsInsideStrings(string $input, string $mustNotContain): void
    {
        $output = (new Redactor())->redactString($input);

        self::assertStringNotContainsString($mustNotContain, $output);
        self::assertStringContainsString(Redactor::MASK, $output);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function secretStrings(): iterable
    {
        yield 'bearer header' => ['Authorization: Bearer eyJhbGciOi.payload.sig', 'eyJhbGciOi'];
        yield 'basic header' => ['Authorization: Basic dXNlcjpwYXNz', 'dXNlcjpwYXNz'];
        yield 'query string' => ['GET /cb?code=1&access_token=ya29.secret&x=1', 'ya29.secret'];
        yield 'json' => ['{"refresh_token":"1//0gSECRET","ok":true}', '1//0gSECRET'];
        yield 'password assignment' => ['login failed password=hunter2 for user', 'hunter2'];
        yield 'dsn credentials' => ['mysql://root:topsecret@127.0.0.1/db', 'topsecret'];
        yield 'api key' => ['api_key: sk_live_123', 'sk_live_123'];
        yield 'Google header' => ['X-Goog-Api-Key: crux-secret', 'crux-secret'];
        yield 'service account assertion' => ['grant_type=test&assertion=header.payload.signature', 'header.payload.signature'];
    }
}
