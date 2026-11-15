<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\BotLogs;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\BotLogs\CrawlerIpRanges;
use SeoControlCenter\Modules\BotLogs\PublishedCrawlerRanges;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\MemoryLogger;

final class CrawlerVerificationTest extends TestCase
{
    public function testMatchesIpv4AndIpv6Prefixes(): void
    {
        $ranges = new CrawlerIpRanges(['66.249.64.0/27', '2001:4860:4801:10::/64', 'nonsense', '10.0.0.0/99', '1.2.3.4/32']);

        self::assertSame(3, $ranges->count());
        self::assertTrue($ranges->contains('66.249.64.31'));
        self::assertFalse($ranges->contains('66.249.64.32'));
        self::assertTrue($ranges->contains('2001:4860:4801:10::abcd'));
        self::assertFalse($ranges->contains('2001:4860:4801:11::1'));
        self::assertTrue($ranges->contains('1.2.3.4'));
        self::assertFalse($ranges->contains('1.2.3.5'));
        self::assertFalse($ranges->contains('not-an-ip'));
        self::assertSame(['66.249.64.0/27', '2001:4860::/32'], CrawlerIpRanges::fromJson('{"creationTime":"x","prefixes":[{"ipv4Prefix":"66.249.64.0/27"},{"ipv6Prefix":"2001:4860::/32"},{"other":1}]}'));
        self::assertSame([], CrawlerIpRanges::fromJson('not json'));
    }

    public function testFetchesCachesAndFallsBackToTheCache(): void
    {
        $dir = sys_get_temp_dir() . '/scc-ranges-' . bin2hex(random_bytes(6));
        $clock = new FrozenClock(new \DateTimeImmutable('2026-11-16 10:00:00'));
        $http = new FakeHttpClient();
        $google = '{"prefixes":[{"ipv4Prefix":"66.249.64.0/19"}]}';
        $http->on('GET', 'https://developers.google.com/static/search/apis/ipranges/googlebot.json', static fn(): HttpResponse => new HttpResponse(200, $google));
        $http->on('GET', 'https://developers.google.com/static/search/apis/ipranges/special-crawlers.json', static fn(): HttpResponse => new HttpResponse(200, '{"prefixes":[{"ipv4Prefix":"66.249.90.0/24"}]}'));
        $http->on('GET', 'https://www.bing.com/', static fn(): HttpResponse => new HttpResponse(503, ''));

        $ranges = (new PublishedCrawlerRanges($http, $clock, new MemoryLogger(), $dir))->ranges();
        self::assertSame(['googlebot'], array_keys($ranges), 'Bing could not be read, so Bingbot stays unverified.');
        self::assertTrue($ranges['googlebot']->contains('66.249.66.1'));
        self::assertTrue($ranges['googlebot']->contains('66.249.90.7'));
        self::assertCount(3, $http->requests);

        // A second source within a day reads the cache; Google is down but the cache still answers.
        $offline = new FakeHttpClient();
        $offline->on('GET', 'https://', static fn(): HttpResponse => new HttpResponse(500, ''));
        $cached = (new PublishedCrawlerRanges($offline, $clock, new MemoryLogger(), $dir))->ranges();
        self::assertSame(['googlebot'], array_keys($cached));
        self::assertSame([], $offline->requestsTo('https://developers.google.com/'), 'Fresh cache: Google is not asked again.');

        foreach (glob($dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
