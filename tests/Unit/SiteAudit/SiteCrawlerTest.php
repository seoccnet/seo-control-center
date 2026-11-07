<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Modules\SiteAudit\Crawler\RobotsTxt;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;
use SeoControlCenter\Tests\Support\Fakes\FakeHostResolver;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;

final class SiteCrawlerTest extends TestCase
{
    private const array HOSTS = ['shop.acme.test', 'www.shop.acme.test'];

    private FakeHttpClient $http;

    private SiteCrawler $crawler;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->crawler = new SiteCrawler($this->http, new FakeHostResolver());
    }

    public function testResourcesAreSampledWithoutTurningExternalTargetsIntoCrawlPages(): void
    {
        $this->http->on('GET', 'https://shop.acme.test/', static fn(): HttpResponse => new HttpResponse(200, '<img src="/missing.png"><img src="/decoration.png" alt=""><script src="/app.js"></script><link rel="stylesheet" href="/app.css"><a href="https://other.test/x">Other</a>', headers: ['content-type' => 'text/html']));
        $result = $this->crawler->fetch('https://shop.acme.test/', self::HOSTS, RobotsTxt::allowAll());
        self::assertSame([], $result->discovered);
        self::assertSame(['image_alt', 'image', 'script', 'stylesheet', 'external'], array_column($result->resources, 'kind'));
        $this->http->on('GET', 'https://other.test/robots.txt', static fn(): HttpResponse => new HttpResponse(200, "User-agent: *\nDisallow: /private"));
        $this->http->on('GET', 'https://other.test/x', static fn(): HttpResponse => new HttpResponse(404, 'missing'));
        self::assertSame(['state' => 'done', 'status' => 404], $this->crawler->resource('https://other.test/x'));
        self::assertSame(['state' => 'skipped', 'status' => null], $this->crawler->resource('https://other.test/private'));
        self::assertSame(['state' => 'skipped', 'status' => null], $this->crawler->resource('http://other.test/x'));
        self::assertSame(['state' => 'skipped', 'status' => null], $this->crawler->resource('https://other.test:8080/x'));
        $issues = \SeoControlCenter\Modules\SiteAudit\ResourceIssues::detect([
            ['pageId' => 1, 'url' => 'https://other.test/x', 'kind' => 'external', 'state' => 'done', 'status' => 404],
            ['pageId' => 1, 'url' => 'https://other.test/denied', 'kind' => 'external', 'state' => 'done', 'status' => 403],
            ['pageId' => 1, 'url' => 'http://other.test/image', 'kind' => 'image_alt', 'state' => 'skipped', 'status' => null],
        ]);
        self::assertSame([\SeoControlCenter\Modules\SiteAudit\IssueType::BrokenExternalLink, \SeoControlCenter\Modules\SiteAudit\IssueType::MissingImageAlt, \SeoControlCenter\Modules\SiteAudit\IssueType::MixedContent], array_column($issues, 'type'));
    }

    public function testHeaderDirectivesApply(): void
    {
        $this->http->on('GET', 'https://shop.acme.test/', static fn(): HttpResponse => new HttpResponse(
            200,
            '<title>Home</title><a href="/next">n</a><a href="https://www.shop.acme.test/w">w</a><a href="https://else.test/">e</a>',
            headers: ['content-type' => 'text/html', 'x-robots-tag' => 'googlebot: noindex, nofollow'],
        ));
        $result = $this->crawler->fetch('https://shop.acme.test/', self::HOSTS, RobotsTxt::allowAll());

        self::assertTrue($result->noindex);
        self::assertSame([], $result->discovered);
        self::assertSame(2, $result->internalLinks);
        self::assertSame(1, $result->externalLinks);
        self::assertSame('Home', $result->page?->title);
    }

    public function testFollowsInternalLinksOnly(): void
    {
        $this->http->on('GET', 'https://shop.acme.test/', static fn(): HttpResponse => new HttpResponse(200, '<a href="/next">n</a><a href="https://else.test/">e</a>', headers: ['content-type' => 'text/html; charset=utf-8']));
        $result = $this->crawler->fetch('https://shop.acme.test/', self::HOSTS, RobotsTxt::allowAll());

        self::assertSame('text/html', $result->contentType);
        self::assertSame(['https://shop.acme.test/next'], $result->discovered);
    }

    public function testRedirectsAreRecordedNotFollowed(): void
    {
        $this->http->on('GET', 'https://shop.acme.test/in', static fn(): HttpResponse => new HttpResponse(301, '', headers: ['location' => '/target']));
        $this->http->on('GET', 'https://shop.acme.test/out', static fn(): HttpResponse => new HttpResponse(302, '', headers: ['location' => 'https://else.test/x']));

        $in = $this->crawler->fetch('https://shop.acme.test/in', self::HOSTS, RobotsTxt::allowAll());
        self::assertSame('https://shop.acme.test/target', $in->redirectTo);
        self::assertSame(['https://shop.acme.test/target'], $in->discovered);
        $out = $this->crawler->fetch('https://shop.acme.test/out', self::HOSTS, RobotsTxt::allowAll());
        self::assertSame('https://else.test/x', $out->redirectTo);
        self::assertSame([], $out->discovered);
        self::assertCount(2, $this->http->requests);
    }

    public function testDoesNotFetchWhatItMustNot(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /private\n", SiteCrawler::PRODUCT_TOKEN);

        self::assertSame('skipped', $this->crawler->fetch('http://shop.acme.test/', self::HOSTS, $robots)->state);
        self::assertSame('skipped', $this->crawler->fetch('https://shop.acme.test:8443/', self::HOSTS, $robots)->state);
        self::assertSame('blocked', $this->crawler->fetch('https://shop.acme.test/private/x', self::HOSTS, $robots)->state);
        self::assertSame('skipped', $this->crawler->fetch('https://foreign.test/', self::HOSTS, $robots)->state);
        self::assertSame([], $this->http->requests);
    }

    public function testCyclicSitemapIndexIsReadOnlyOnce(): void
    {
        $this->http->on('GET', 'https://shop.acme.test/sitemap.xml', static fn(): HttpResponse => new HttpResponse(
            200,
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://shop.acme.test/sitemap.xml</loc></sitemap></sitemapindex>'
        ));
        self::assertSame([], $this->crawler->sitemapUrls('shop.acme.test', RobotsTxt::allowAll(), self::HOSTS, 100));
        self::assertCount(1, $this->http->requests);
    }

    public function testNonHtmlAndFailuresAreRecorded(): void
    {
        $this->http->on('GET', 'https://shop.acme.test/file.pdf', static fn(): HttpResponse => new HttpResponse(200, '%PDF<a href="/x">', headers: ['content-type' => 'application/pdf'], durationMs: 2500));
        $pdf = $this->crawler->fetch('https://shop.acme.test/file.pdf', self::HOSTS, RobotsTxt::allowAll());
        self::assertNull($pdf->page);
        self::assertSame(2500, $pdf->durationMs);
        self::assertSame([], $pdf->discovered);

        $failed = $this->crawler->fetch('https://shop.acme.test/unrouted', self::HOSTS, RobotsTxt::allowAll());
        self::assertSame('done', $failed->state);
        self::assertNull($failed->statusCode);
        self::assertStringContainsString('No fake route', (string) $failed->error);

        $this->http->on('GET', 'https://shop.acme.test/robots.txt', static fn(): HttpResponse => new HttpResponse(404, ''));
        self::assertTrue($this->crawler->robots('shop.acme.test')->allows('/anything'), 'missing robots.txt allows all');
    }

    public function testRobotsFailuresDoNotAllowCrawling(): void
    {
        foreach ([503, 429, 302] as $status) {
            $this->http->on('GET', 'https://shop.acme.test/robots.txt', static fn(): HttpResponse => new HttpResponse($status, ''));
            try {
                $this->crawler->robots('shop.acme.test');
                self::fail('Robots failure must stop crawling.');
            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                self::assertSame($status !== 302, $e->retryable);
            }
        }
        self::assertCount(3, $this->http->requests);
    }

    public function testLongPaginatedSeriesAreNotFollowed(): void
    {
        $this->http->on('GET', 'https://shop.acme.test/list', static fn(): HttpResponse => new HttpResponse(200, '<a href="?page=2">2</a><a href="?page=10">10</a><a href="?page=11">11</a><a href="?page=37">37</a>', headers: ['content-type' => 'text/html']));
        $result = $this->crawler->fetch('https://shop.acme.test/list', self::HOSTS, RobotsTxt::allowAll());

        self::assertSame(['https://shop.acme.test/list?page=2', 'https://shop.acme.test/list?page=10'], $result->discovered);
        self::assertSame(4, $result->internalLinks);
    }

    public function testReadsSitemapsFromRobotsTxtAndIndexes(): void
    {
        $robots = RobotsTxt::parse("Sitemap: https://shop.acme.test/sitemap_index.xml\nSitemap: https://evil.test/sitemap.xml\n", SiteCrawler::PRODUCT_TOKEN);
        $this->http->on('GET', 'https://shop.acme.test/sitemap_index.xml', static fn(): HttpResponse => new HttpResponse(200, '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://shop.acme.test/products.xml.gz</loc></sitemap><sitemap><loc>https://shop.acme.test/broken.xml</loc></sitemap></sitemapindex>'));
        $this->http->on('GET', 'https://shop.acme.test/products.xml.gz', static fn(): HttpResponse => new HttpResponse(200, (string) gzencode('<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://shop.acme.test/a</loc></url><url><loc>https://www.shop.acme.test/b#x</loc></url><url><loc>https://else.test/c</loc></url><url><loc>https://shop.acme.test/a</loc></url><url><loc>https://shop.acme.test/d</loc></url></urlset>')));
        $this->http->on('GET', 'https://shop.acme.test/broken.xml', static fn(): HttpResponse => new HttpResponse(500, ''));

        self::assertSame(['https://shop.acme.test/a', 'https://www.shop.acme.test/b', 'https://shop.acme.test/d'], $this->crawler->sitemapUrls('shop.acme.test', $robots, self::HOSTS, 10));
        self::assertSame(['https://shop.acme.test/a', 'https://www.shop.acme.test/b'], $this->crawler->sitemapUrls('shop.acme.test', $robots, self::HOSTS, 2));
        self::assertSame([], $this->http->requestsTo('https://evil.test'));
    }

    public function testFallsBackToSitemapXml(): void
    {
        $this->http->on('GET', 'https://shop.acme.test/sitemap.xml', static fn(): HttpResponse => new HttpResponse(200, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://shop.acme.test/x</loc></url></urlset>'));

        self::assertSame(['https://shop.acme.test/x'], $this->crawler->sitemapUrls('shop.acme.test', RobotsTxt::allowAll(), self::HOSTS, 10));
    }
}
