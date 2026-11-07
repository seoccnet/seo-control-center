<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;

final class HtmlPageTest extends TestCase
{
    public function testReadsTheOnPageElements(): void
    {
        $page = HtmlPage::parse(<<<'HTML'
            <!doctype html>
            <html><head>
              <title>  Garden   tools | Acme </title>
              <meta name="Description" content="Spades, rakes &amp; more.">
              <meta name="robots" content="index, follow">
              <link rel="alternate canonical" href="/garden">
            </head>
            <body>
              <h1>Garden <b>tools</b></h1>
              <h1>Second</h1>
              <p>Grüße aus dem Garten, and a few more words in this little paragraph.</p>
              <script>var hidden = "not counted words here";</script>
              <a href="/spades">Spades</a>
              <a href="rakes?x=1#reviews">Rakes</a>
              <a href="/spades">Again</a>
              <a href="https://other.test/">Partner</a>
              <a href="mailto:a@b.test">Mail</a>
            </body></html>
            HTML, 'https://shop.acme.test/garden/');

        self::assertSame('Garden tools | Acme', $page->title);
        self::assertSame('Spades, rakes & more.', $page->description);
        self::assertSame(2, $page->h1Count);
        self::assertSame('Garden tools', $page->h1);
        self::assertSame('https://shop.acme.test/garden', $page->canonical);
        self::assertFalse($page->noindex);
        self::assertFalse($page->nofollow);
        self::assertSame(['https://shop.acme.test/spades', 'https://shop.acme.test/garden/rakes?x=1', 'https://other.test/'], $page->links);
        // "Garden tools Second" + 13 paragraph words + 5 link texts; the script is not counted.
        self::assertSame(21, $page->wordCount);
    }

    public function testRobotsMetaAndBaseHref(): void
    {
        $page = HtmlPage::parse('<html><head><base href="https://shop.acme.test/de/"><meta name="robots" content="NOINDEX,nofollow"></head><body><a href="seite">x</a></body></html>', 'https://shop.acme.test/');

        self::assertTrue($page->noindex);
        self::assertTrue($page->nofollow);
        self::assertNull($page->title);
        self::assertNull($page->description);
        self::assertSame(0, $page->h1Count);
        self::assertSame(['https://shop.acme.test/de/seite'], $page->links);
    }

    public function testCopesWithBrokenMarkup(): void
    {
        $page = HtmlPage::parse('<title>Oops</title><p><a href="/x">x<div></p></span><a href=/y>y', 'https://shop.acme.test/');

        self::assertSame('Oops', $page->title);
        self::assertSame(['https://shop.acme.test/x', 'https://shop.acme.test/y'], $page->links);
    }

    public function testReadsStructuredDataOpenGraphTypeAndAuthorSignals(): void
    {
        $page = HtmlPage::parse(<<<'HTML'
            <!doctype html><html><head>
              <meta property="og:type" content="Article">
              <script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"BlogPosting","author":{"@type":"Person","name":"Ana"}},{"@type":["BreadcrumbList","Thing"]},{"@type":"Bad Type!"}]}</script>
              <script type="application/ld+json">not json</script>
            </head><body>
              <div itemscope itemtype="https://schema.org/Product"><span itemprop="name">Spade</span></div>
              <p>Body text.</p>
            </body></html>
            HTML, 'https://shop.acme.test/blog/post');
        self::assertSame(['BlogPosting', 'BreadcrumbList', 'Thing', 'Product'], $page->schemaTypes, 'Page-level types; a nested author Person is not one.');
        self::assertSame('article', $page->ogType);
        self::assertTrue($page->hasAuthor);

        $plain = HtmlPage::parse('<html><head><meta property="og:type" content="caf&eacute; type"></head><body><p>Text</p></body></html>', 'https://shop.acme.test/');
        self::assertSame([], $plain->schemaTypes);
        self::assertNull($plain->ogType, 'Unsafe og:type values are dropped.');
        self::assertFalse($plain->hasAuthor);

        foreach (['<meta name="author" content="Ana">', '<a rel="author" href="/ana">Ana</a>', '<span class="post-byline">By Ana</span>'] as $signal) {
            self::assertTrue(HtmlPage::parse('<html><head>' . $signal . '</head><body>' . $signal . '</body></html>', 'https://shop.acme.test/')->hasAuthor, $signal);
        }
        self::assertFalse(HtmlPage::parse('<html><head><meta name="author" content=" "></head><body></body></html>', 'https://shop.acme.test/')->hasAuthor);
    }

    public function testReadsTheBusinessDetails(): void
    {
        $page = HtmlPage::parse(<<<'HTML'
            <html><head><script type="application/ld+json">{"@context":"https://schema.org","@graph":[
              {"@type":"WebSite","name":"Northwind Goods"},
              {"@type":["Store"],"name":" Northwind Goods ","telephone":"+44 33 123-456",
               "address":{"@type":"PostalAddress","streetAddress":"Main St 5","postalCode":"12345","addressLocality":"Springfield"}},
              {"@type":"Person","name":"Ana","telephone":"+44 61 000 000","address":"Elsewhere 1"}
            ]}</script></head>
            <body><a href="tel:+4433123456">Call</a> <a href="tel:061%20222%20333">Mobile</a> <a href="tel:12">short</a></body></html>
            HTML, 'https://shop.acme.test/contact');

        self::assertSame(['types' => ['Store'], 'name' => 'Northwind Goods', 'phones' => ['+4433123456', '061222333'], 'address' => 'Main St 5, 12345, Springfield'], $page->business);
        self::assertSame(['types' => [], 'name' => null, 'phones' => [], 'address' => null], HtmlPage::parse('<html><body><p>No details</p></body></html>', 'https://shop.acme.test/')->business, 'read, but nothing stated');
        self::assertSame('+4461000000', HtmlPage::phone(' +44 (61) 000-000 '));
    }
}
