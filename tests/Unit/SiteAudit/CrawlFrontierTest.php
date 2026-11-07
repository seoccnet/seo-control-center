<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\SiteAudit\Crawler\CrawlFrontier;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;
use SeoControlCenter\Tests\Support\Fakes\FakeHostResolver;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;

final class CrawlFrontierTest extends TestCase
{
    public function testRedirectsResumeOneHopAndCacheFinalRulesForOriginalHost(): void
    {
        $http = new FakeHttpClient();
        $resolver = new FakeHostResolver();
        $crawler = new SiteCrawler($http, $resolver);
        $http->on('GET', 'https://site.test/robots.txt', static fn(): HttpResponse => new HttpResponse(301, '', headers: ['location' => 'https://cdn.test/rules']));
        $http->on('GET', 'https://cdn.test/rules', static fn(): HttpResponse => new HttpResponse(200, "User-agent: *\nDisallow: /private"));
        $frontier = new CrawlFrontier();
        $frontier->advanceRobots($crawler, 'site.test', 100);
        self::assertNull($frontier->cached('site.test', 100));
        self::assertCount(1, $http->requests);
        $frontier = CrawlFrontier::fromArray($frontier->toArray());
        $frontier->advanceRobots($crawler, 'site.test', 101);
        self::assertFalse($frontier->cached('site.test', 101)?->allows('/private'));
        self::assertTrue($frontier->cached('site.test', 101)->allows('/public'));
        self::assertNull($frontier->cached('site.test', 3701));
        self::assertCount(2, $http->requests);
    }

    public function testPrivateRedirectNeverFetched(): void
    {
        $http = new FakeHttpClient();
        $resolver = new FakeHostResolver();
        $resolver->map['private.test'] = ['127.0.0.1'];
        $crawler = new SiteCrawler($http, $resolver);
        $http->on('GET', 'https://site.test/robots.txt', static fn(): HttpResponse => new HttpResponse(302, '', headers: ['location' => 'https://private.test/rules']));
        $frontier = new CrawlFrontier();
        $frontier->advanceRobots($crawler, 'site.test', 100);
        try {
            $frontier->advanceRobots($crawler, 'site.test', 101);
            self::fail('Private hop must be refused.');
        } catch (IntegrationException) {
            self::assertCount(1, $http->requests);
            self::assertNull($frontier->cached('site.test', 101));
        }
    }

    public function testSitemapIndexIsDurableBoundedAndCycleSafe(): void
    {
        $http = new FakeHttpClient();
        $crawler = new SiteCrawler($http, new FakeHostResolver());
        $http->on('GET', 'https://site.test/sitemap.xml', static fn(): HttpResponse => new HttpResponse(200, '<sitemapindex><sitemap><loc>https://site.test/child.xml</loc></sitemap></sitemapindex>'));
        $http->on('GET', 'https://site.test/child.xml', static fn(): HttpResponse => new HttpResponse(200, '<urlset><url><loc>https://site.test/page</loc></url></urlset>'));
        $frontier = new CrawlFrontier();
        $frontier->initialize('site.test', \SeoControlCenter\Modules\SiteAudit\Crawler\RobotsTxt::allowAll(), ['site.test']);
        self::assertSame([], $frontier->advanceSitemap($crawler, ['site.test'], 10));
        self::assertCount(1, $http->requests);
        $frontier = CrawlFrontier::fromArray($frontier->toArray());
        self::assertSame(['https://site.test/page'], $frontier->advanceSitemap($crawler, ['site.test'], 10));
        self::assertTrue($frontier->seeded);
        self::assertCount(2, $http->requests);
    }
}
