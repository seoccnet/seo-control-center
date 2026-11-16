<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;
use SeoControlCenter\Modules\SiteAudit\IssueDetector;
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Modules\SiteAudit\ResourceIssues;

/** Sitemap audit and image SEO checks (1.5.0). */
final class SitemapAndImageChecksTest extends TestCase
{
    public function testSitemapAgainstTheCrawl(): void
    {
        $pages = [
            self::page(1, '/', 0, true),
            self::page(2, '/gone', 1, true, status: 404),
            new AuditPage(3, 'https://acme.test/old', 1, 'done', 301, 'text/html', 'https://acme.test/new', inSitemap: true),
            self::page(4, '/private', 1, true, noindex: true),
            self::page(5, '/print', 1, true, canonical: 'https://acme.test/a'),
            new AuditPage(6, 'https://acme.test/admin', 1, 'blocked', inSitemap: true),
            self::page(7, '/a', 1, false),
            self::page(8, '/b?color=red', 1, false),
            self::page(9, '/list?page=2', 1, false),
            self::page(10, '/orphan-only-in-links', AuditPage::UNLINKED, false),
            self::page(11, '/hidden', 1, false, noindex: true),
        ];
        $found = self::issues($pages, [7 => 3, 8 => 1, 9 => 1, 11 => 1], 100);

        self::assertSame('HTTP 404', $found[2][IssueType::SitemapBroken->value]);
        self::assertSame('301 → /new', $found[3][IssueType::SitemapRedirect->value]);
        self::assertSame('noindex', $found[4][IssueType::SitemapNotIndexable->value]);
        self::assertSame('Canonical points to /a', $found[5][IssueType::SitemapNotIndexable->value]);
        self::assertSame('Blocked by robots.txt', $found[6][IssueType::SitemapNotIndexable->value]);
        self::assertSame('Linked from 3 pages', $found[7][IssueType::MissingFromSitemap->value]);
        foreach ([8, 9, 10, 11] as $id) {
            self::assertArrayNotHasKey(IssueType::MissingFromSitemap->value, $found[$id] ?? [], 'Query, pagination, unlinked and noindex pages are not reported (' . $id . ').');
        }

        // A sitemap that may have been cut off at the page limit is not used to call pages missing.
        $cut = self::issues($pages, [7 => 3], 6);
        self::assertArrayNotHasKey(IssueType::MissingFromSitemap->value, $cut[7] ?? []);
        self::assertArrayHasKey(IssueType::SitemapBroken->value, $cut[2]);

        // Audits before 1.5.0 and sites without a sitemap are not judged.
        $old = [new AuditPage(1, 'https://acme.test/', 0, 'done', 200, 'text/html', title: 'Home', description: 'x', h1Count: 1, wordCount: 400)];
        self::assertSame([], array_filter(IssueDetector::detect($old, [], true, [], 100)['issues'], static fn(array $i): bool => str_starts_with($i['type']->value, 'sitemap') || $i['type'] === IssueType::MissingFromSitemap));
    }

    public function testImagesInTheHtml(): void
    {
        $html = HtmlPage::parse('<html><body><header><img src="/logo.png" width="100" height="40"></header><main>'
            . '<img src="/hero.jpg" loading="lazy" alt="Hero">'
            . '<img src="/b.jpg" width="400" height="300" alt="">'
            . '<img src="/c.jpg" width="400" alt="">'
            . '<img src="data:image/gif;base64,R0lGOD" alt="">'
            . '<img src="/pixel.gif" width="1" height="1" alt="">'
            . '</main></body></html>', 'https://acme.test/');

        self::assertSame(['images' => 4, 'missing_dimensions' => 2, 'lazy_first' => true], $html->images);

        $page = new AuditPage(1, 'https://acme.test/p', 1, 'done', 200, 'text/html', title: 'Product page', description: 'A product', h1Count: 1, wordCount: 400, imageSignals: $html->images);
        $found = self::issues([$page], [], 0);
        self::assertSame('2 of 4 images have no width and height', $found[1][IssueType::ImageMissingDimensions->value]);
        self::assertArrayHasKey(IssueType::LazyMainImage->value, $found[1]);
        self::assertSame(['images' => 1, 'missing_dimensions' => 0, 'lazy_first' => false], HtmlPage::parse('<main><img src="/a.webp" width="10" height="10"></main>', 'https://acme.test/')->images);
    }

    public function testImageFileSizesAndFormats(): void
    {
        $issues = ResourceIssues::detect([
            ['pageId' => 1, 'url' => 'https://acme.test/hero.jpg', 'kind' => 'image', 'state' => 'done', 'status' => 206, 'bytes' => 512 * 1024, 'type' => 'image/jpeg'],
            ['pageId' => 1, 'url' => 'https://acme.test/icon.png', 'kind' => 'image', 'state' => 'done', 'status' => 206, 'bytes' => 150 * 1024, 'type' => 'image/png'],
            ['pageId' => 2, 'url' => 'https://acme.test/photo.webp', 'kind' => 'image', 'state' => 'done', 'status' => 200, 'bytes' => 350 * 1024, 'type' => 'image/webp'],
            ['pageId' => 2, 'url' => 'https://acme.test/app.js', 'kind' => 'script', 'state' => 'done', 'status' => 206, 'bytes' => 900 * 1024, 'type' => 'text/javascript'],
            ['pageId' => 3, 'url' => 'https://acme.test/missing.jpg', 'kind' => 'image', 'state' => 'done', 'status' => 404, 'bytes' => 400 * 1024, 'type' => 'text/html'],
        ]);
        $found = HreflangCheckTest::byPage($issues);

        self::assertSame('https://acme.test/hero.jpg (512 KB, image/jpeg)', $found[1][IssueType::LargeImage->value]);
        self::assertSame('https://acme.test/hero.jpg (512 KB, image/jpeg) · https://acme.test/icon.png (150 KB, image/png)', $found[1][IssueType::LegacyImageFormat->value]);
        self::assertArrayNotHasKey(IssueType::LegacyImageFormat->value, $found[2], 'WebP is already modern.');
        self::assertArrayHasKey(IssueType::LargeImage->value, $found[2]);
        self::assertSame([IssueType::BrokenAsset->value], array_keys($found[3]), 'Error pages are not judged as images.');
    }

    /**
     * @param list<AuditPage> $pages
     * @param array<int, int> $inbound
     * @return array<int, array<string, string>>
     */
    private static function issues(array $pages, array $inbound, int $sitemapLimit): array
    {
        $found = [];
        foreach (IssueDetector::detect($pages, $inbound, true, [], $sitemapLimit)['issues'] as $issue) {
            $found[$issue['pageId']][$issue['type']->value] = (string) $issue['detail'];
        }

        return $found;
    }

    private static function page(int $id, string $path, int $depth, bool $inSitemap, int $status = 200, bool $noindex = false, ?string $canonical = null): AuditPage
    {
        return new AuditPage($id, 'https://acme.test' . $path, $depth, 'done', $status, 'text/html', title: 'Page ' . $id, description: 'About ' . $id, h1Count: 1, wordCount: 400, noindex: $noindex, canonical: $canonical, schemaTypes: ['WebPage'], inSitemap: $inSitemap);
    }
}
