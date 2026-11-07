<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\CachingHostResolver;
use SeoControlCenter\Core\Http\Client\CurlHttpClient;
use SeoControlCenter\Core\Http\Client\HostResolver;

final class CachingHostResolverTest extends TestCase
{
    public function testRepeatsNoLookupWithinTheTtlAndNeverCachesFailures(): void
    {
        $inner = new class implements HostResolver {
            public int $calls = 0;

            /** @var list<string> */
            public array $answer = [];

            public function addresses(string $host): array
            {
                ++$this->calls;

                return $this->answer;
            }
        };
        $resolver = new CachingHostResolver($inner);

        self::assertSame([], $resolver->addresses('api.example.com'));
        $inner->answer = ['93.184.216.34'];
        self::assertSame(['93.184.216.34'], $resolver->addresses('api.example.com'), 'A failed lookup is retried.');
        self::assertSame(['93.184.216.34'], $resolver->addresses('API.example.com'));
        self::assertSame(2, $inner->calls);

        $expired = new CachingHostResolver($inner, 0);
        $expired->addresses('api.example.com');
        $expired->addresses('api.example.com');
        self::assertSame(4, $inner->calls);
    }

    public function testRetryAfterAcceptsSecondsAndHttpDates(): void
    {
        $now = (new \DateTimeImmutable('2026-10-10 10:00:00 UTC'))->getTimestamp();

        self::assertSame(120, CurlHttpClient::retryAfter('120', $now));
        self::assertSame(90, CurlHttpClient::retryAfter('Sat, 10 Oct 2026 10:01:30 GMT', $now));
        self::assertSame(0, CurlHttpClient::retryAfter('Sat, 10 Oct 2026 09:00:00 GMT', $now));
        self::assertSame(86_400, CurlHttpClient::retryAfter('99999999', $now));
        self::assertNull(CurlHttpClient::retryAfter('soon', $now));
        self::assertNull(CurlHttpClient::retryAfter('', $now));
    }
}
