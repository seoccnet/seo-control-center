<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;
use SeoControlCenter\Modules\SiteAudit\IssueDetector;
use SeoControlCenter\Modules\SiteAudit\IssueType;

/** Articles without source links (0.42.0): only other sites linked from the text count. */
final class SourceLinksTest extends TestCase
{
    public function testOnlyOtherSitesLinkedFromTheTextCount(): void
    {
        $html = '<html><body><nav><a href="https://facebook.com/shop">Facebook</a></nav><main><article>'
            . '<p>A 2025 study by <a href="https://www.uni.example/study">the university</a> found mold in 12 % of samples, and the <a href="https://agency.example/report">agency report</a> agrees.</p>'
            . '<p>See also <a href="https://agency.example/other">another agency page</a> and <a href="/blog/related">our related guide</a> and <a href="https://www.shop.test/seeds">our seeds</a>.</p>'
            . '</article></main><footer><a href="https://instagram.com/shop">Instagram</a></footer></body></html>';
        self::assertSame(2, HtmlPage::parse($html, 'https://shop.test/blog/mold')->sourceLinks, 'Two other sites from the text; menu, footer, own-site and www links do not count.');
        self::assertSame(0, HtmlPage::parse('<html><body><nav><a href="https://facebook.com/x">Facebook</a></nav><p>No sources here.</p></body></html>', 'https://shop.test/blog/a')->sourceLinks);
    }

    public function testLongArticlesWithoutSourcesGetANoticeAndUnknownStaysUnknown(): void
    {
        $page = static fn(int $id, string $path, ?int $sources, int $words, ?string $og = 'article'): AuditPage => new AuditPage($id, 'https://shop.test' . $path, 1, 'done', 200, 'text/html', title: 'T' . $id, description: 'D' . $id, h1Count: 1, h1: 'H' . $id, wordCount: $words, schemaTypes: ['BlogPosting'], ogType: $og, hasAuthor: true, sourceLinks: $sources);
        $result = IssueDetector::detect([
            $page(1, '/blog/no-sources', 0, 900),
            $page(2, '/blog/cited', 3, 900),
            $page(3, '/blog/short', 0, 300),
            $page(4, '/blog/legacy', null, 900),
            new AuditPage(5, 'https://shop.test/seeds/a', 1, 'done', 200, 'text/html', title: 'T5', description: 'D5', h1Count: 1, h1: 'H5', wordCount: 900, schemaTypes: ['Product'], ogType: 'product', hasAuthor: null, sourceLinks: 0),
        ], [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1], false);
        $found = [];
        foreach ($result['issues'] as $issue) {
            $found[$issue['type']->value][$issue['pageId']] = $issue['detail'];
        }
        self::assertSame([1 => '900 words, no link to another site in the text'], $found['missing_sources'] ?? null, 'Cited, short, legacy and non-article pages are not reported.');
        self::assertSame(['notice', 'Article without source links'], [IssueType::MissingSources->severity(), IssueType::MissingSources->label()]);
    }
}
