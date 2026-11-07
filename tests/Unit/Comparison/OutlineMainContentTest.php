<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Comparison;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Comparison\OutlineExtractor;

/** 0.28.0 live check, finding 8: site-template headings were read as the article's own. */
final class OutlineMainContentTest extends TestCase
{
    public function testOnlyTheMainContentHeadingsCount(): void
    {
        $html = '<!doctype html><html><head><title>Brandywine cultivar review</title></head><body class="no-sidebar">'
            . '<div id="page" class="site menu-closed">'
            . '<header><h2>Free shipping on all orders</h2><nav><h3>Shop</h3></nav></header>'
            . '<main><article><h1>Brandywine cultivar review</h1>'
            . '<div class="elementor-widget elementor-widget-heading"><h2>What is the Brandywine cultivar?</h2></div>' . self::text()
            . '<header class="entry-section"><h2>Effects and experience</h2></header>' . self::text()
            . '<h3>NPK level</h3>' . self::text()
            . '<div class="related-posts"><h3>You may also like</h3></div>'
            . '</article><aside><h2>Popular cultivars</h2></aside></main>'
            . '<div class="modal privacy-policy" aria-modal="true"><h2>SECTION 1 – PERSONAL INFORMATION WE COLLECT</h2><h3>1.2 Newsletters</h3><h3>2.1 How do you withdraw consent?</h3></div>'
            . '<div id="cookie-consent"><h2>We use cookies</h2></div><div hidden><h2>Hidden heading</h2></div>'
            . '<footer><h2>More helpful info</h2></footer></div></body></html>';
        $page = OutlineExtractor::extract($html, 'https://rival.test/blog/brandywine');

        self::assertSame(['What is the Brandywine cultivar?', 'Effects and experience', 'NPK level'], array_column($page->outline, 'text'));
        self::assertSame(['What is the Brandywine cultivar?'], $page->questions, 'No questions from the privacy policy.');
    }

    public function testWithoutMainContentOnlySiteChromeIsSkipped(): void
    {
        $html = '<!doctype html><html><head><title>Guide</title></head><body><div class="wrapper">'
            . '<h2>Watering schedule</h2>' . self::text() . '<h2>Soil</h2>' . self::text()
            . '<div class="newsletter-box"><h2>Join our newsletter</h2></div><footer><h2>About us</h2></footer></div></body></html>';

        self::assertSame(['Watering schedule', 'Soil'], array_column(OutlineExtractor::extract($html, 'https://a.test/guide')->outline, 'text'));
        $chromeOnly = '<!doctype html><html><head><title>x</title></head><body>' . self::text() . '<footer><h2>Only footer heading</h2></footer></body></html>';
        self::assertSame(['Only footer heading'], array_column(OutlineExtractor::extract($chromeOnly, 'https://a.test/x')->outline, 'text'), 'Nothing else: the headings found are kept.');
    }

    private static function text(): string
    {
        return '<p>' . str_repeat('growers often ask about this cultivar and its effects ', 6) . '</p>';
    }
}
