<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\IndexNow;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Http\Client\SystemHostResolver;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\IndexNow\Api\IndexNowClient;
use SeoControlCenter\Modules\IndexNow\SitemapParser;
use SeoControlCenter\Tests\Support\Fakes\FakeHostResolver;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;

/**
 * Sitemap parsing, the IndexNow client and the public-address check.
 */
final class IndexNowUnitTest extends TestCase
{
    public function testParsesUrlsetAndSitemapIndex(): void
    {
        $urlset = SitemapParser::parse('<?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://shop.test/</loc><lastmod>2026-09-30</lastmod></url>
              <url><loc> https://shop.test/a?x=1&amp;y=2 </loc></url>
              <url><loc>ftp://shop.test/file</loc></url>
            </urlset>');
        self::assertSame([
            ['loc' => 'https://shop.test/', 'lastmod' => '2026-09-30'],
            ['loc' => 'https://shop.test/a?x=1&y=2', 'lastmod' => null],
        ], $urlset['urls']);

        $index = SitemapParser::parse('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://shop.test/post-sitemap.xml</loc></sitemap></sitemapindex>');
        self::assertSame(['https://shop.test/post-sitemap.xml'], $index['sitemaps']);
        self::assertSame([], $index['urls']);
    }

    public function testRejectsDoctypesAndOtherDocuments(): void
    {
        foreach (['<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><urlset><url><loc>&e;</loc></url></urlset>', '<html><body>Not found</body></html>'] as $xml) {
            try {
                SitemapParser::parse($xml);
                self::fail('Expected a rejection.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSubmitsToIndexNowAndExplainsAnswers(): void
    {
        $http = new FakeHttpClient();
        $http->on('POST', IndexNowClient::ENDPOINT, static fn(): HttpResponse => new HttpResponse(202, ''));
        $client = new IndexNowClient($http, new FakeHostResolver());

        $result = $client->submit('shop.test', str_repeat('a', 32), ['https://shop.test/a', 'https://shop.test/b']);

        self::assertTrue($result['ok']);
        self::assertStringContainsString('validating the key file', $result['message']);
        $body = json_decode((string) $http->requests[0]['body'], true);
        self::assertSame(['host' => 'shop.test', 'key' => str_repeat('a', 32), 'keyLocation' => 'https://shop.test/' . str_repeat('a', 32) . '.txt', 'urlList' => ['https://shop.test/a', 'https://shop.test/b']], $body);

        $http->on('POST', IndexNowClient::ENDPOINT, static fn(): HttpResponse => new HttpResponse(403, ''));
        self::assertFalse($client->submit('shop.test', str_repeat('a', 32), ['https://shop.test/a'])['ok']);
        $http->on('POST', IndexNowClient::ENDPOINT, static fn(): HttpResponse => new HttpResponse(429, ''));
        $this->expectException(IntegrationException::class);
        $client->submit('shop.test', str_repeat('a', 32), ['https://shop.test/a']);
    }

    public function testRefusesHostsOnPrivateAddressesAndReadsGzip(): void
    {
        $http = new FakeHttpClient();
        $http->on('GET', 'https://shop.test/sitemap.xml.gz', static fn(): HttpResponse => new HttpResponse(200, (string) gzencode('<urlset/>')));
        $resolver = new FakeHostResolver();
        $resolver->map['intranet.test'] = ['10.0.0.5'];
        $client = new IndexNowClient($http, $resolver);

        self::assertSame('<urlset/>', $client->fetch('https://shop.test/sitemap.xml.gz'));
        try {
            $client->fetch('https://intranet.test/sitemap.xml');
            self::fail('A private address must be refused.');
        } catch (IntegrationException $e) {
            self::assertStringContainsString('public address', $e->getMessage());
        }
        self::assertCount(1, $http->requests, 'Nothing was sent to the private host.');
        self::assertFalse(SystemHostResolver::allPublic(['127.0.0.1']));
        self::assertFalse(SystemHostResolver::allPublic(['93.184.216.34', '::1']));
        self::assertFalse(SystemHostResolver::allPublic([]));
        self::assertTrue(SystemHostResolver::allPublic(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']));
    }
}
