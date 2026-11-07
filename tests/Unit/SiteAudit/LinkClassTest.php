<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\InternalLinks\LinkClass;
use SeoControlCenter\Domain\InternalLinks\LinkIntent;
use SeoControlCenter\Domain\InternalLinks\LinkSuggester;
use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;

/** Internal-link classes and the intent check (ADR-042, 0.35.0). */
final class LinkClassTest extends TestCase
{
    public function testARelatedArticleCardIsNotAnEditorialLink(): void
    {
        $html = '<html><body><main><article><p>Curing takes patience: read our <a href="/blog/drying">drying guide</a> before you jar the blossoms, and check humidity daily.</p>'
            . '<section class="related-posts"><a href="/blog/curing"><img src="c.jpg" alt=""><h3>How to cure pepper</h3></a></section>'
            . '<div class="product-card"><a href="/seeds/brandywine">Brandywine seeds</a><span>€29.90</span></div>'
            . '<div><a href="/seeds/zkittlez"><img src="z.jpg" alt="Zkittlez"></a><p>Zkittlez €34</p></div>'
            . '<nav class="breadcrumb"><a href="/blog">Blog</a></nav><ol class="breadcrumbs"><li><a href="/">Home</a></li></ol>'
            . '<div class="teaser"><a href="/blog/pistils">Pistils explained</a></div>'
            . '<p><a href="/blog/flavours">Flavours</a></p></article></main><footer><a href="/about">About</a></footer></body></html>';
        $links = HtmlPage::parse($html, 'https://shop.test/blog/mold')->linkContexts;
        $class = static fn(string $path): string => $links['https://shop.test' . $path]['class'] . '/' . $links['https://shop.test' . $path]['confidence'];

        self::assertSame('editorial/high', $class('/blog/drying'));
        self::assertSame('related/high', $class('/blog/curing'), 'A related-article card is never a link in the text.');
        self::assertSame('product/high', $class('/seeds/brandywine'));
        self::assertSame('product/medium', $class('/seeds/zkittlez'), 'An image card with a price is a product card.');
        self::assertSame('template/high', $class('/blog'), 'Breadcrumbs in a nav element are a page region.');
        self::assertSame('template/medium', $class('/'), 'Breadcrumbs named by class are a template.');
        self::assertSame('related/high', $class('/blog/pistils'));
        self::assertSame('other/low', $class('/blog/flavours'), 'A link that is its whole paragraph is not in a sentence.');
        self::assertSame('template/high', $class('/about'));
    }

    public function testTheTextOccurrenceWinsOverACardToTheSamePage(): void
    {
        $html = '<html><body><main><div class="related"><a href="/blog/curing">How to cure pepper</a></div>'
            . '<p>When the stems snap, start <a href="/blog/curing">curing your blossoms</a> in airtight jars for at least two weeks.</p></main></body></html>';
        $link = HtmlPage::parse($html, 'https://shop.test/')->linkContexts['https://shop.test/blog/curing'];
        self::assertSame(['curing your blossoms', 'editorial'], [$link['anchor'], $link['class']]);
    }

    public function testRepeatedTemplateLinksAreCountedOnce(): void
    {
        $links = [];
        foreach (range(1, 40) as $i) {
            $links[] = ['url' => 'https://shop.test/p' . $i, 'anchor' => 'Rewards', 'place' => 'footer', 'class' => 'template', 'confidence' => 'high', 'context' => null];
        }
        $links[] = ['url' => 'https://shop.test/blog/a', 'anchor' => 'reward points', 'place' => 'body', 'class' => 'editorial', 'confidence' => 'high', 'context' => 'Earn reward points'];
        $links[] = ['url' => 'https://shop.test/old', 'anchor' => 'x', 'place' => 'body', 'class' => null, 'confidence' => null, 'context' => null];
        $summary = LinkClass::summarize($links);

        self::assertSame(['editorial', 'unclassified', 'template'], array_keys($summary), 'Text first; a body link of an audit crawled before 0.35.0 is unclassified, not "other".');
        self::assertSame('Not classified (crawled before 0.35.0)', $summary['unclassified']['label']);
        self::assertSame([1, 40], [$summary['template']['count'], $summary['template']['pages']], 'One footer context on 40 pages.');
        self::assertCount(1, $summary['template']['links']);
    }

    public function testFreeSeedsInARewardParagraphDoNotLinkToAPrizeDraw(): void
    {
        self::assertSame('the paragraph is about rewards or loyalty points, the page about a prize draw or giveaway', LinkIntent::conflict('free tomato seeds Free Tomato Seeds Giveaways giveaways', 'Earn points on every order and redeem them for free tomato seeds.'));
        self::assertNull(LinkIntent::conflict('free tomato seeds giveaways', 'Enter our monthly giveaway to win free tomato seeds.'), 'The same purpose links.');
        self::assertNull(LinkIntent::conflict('sunflower seeds', 'Earn points when you buy sunflower seeds.'), 'A target without a distinct purpose is not checked.');

        $pages = [1 => ['url' => 'https://s/giveaways', 'inbound' => 9, 'title' => 'Win free tomato seeds'], 2 => ['url' => 'https://s/rewards', 'inbound' => 3], 3 => ['url' => 'https://s/blog/news', 'inbound' => 3]];
        $paragraphs = [
            ['page_id' => 2, 'text' => 'Collect points on every order and choose free tomato seeds when you reach a reward tier.'],
            ['page_id' => 3, 'text' => 'This month you can win free tomato seeds in our big autumn giveaway for all newsletter readers.'],
        ];
        $leftOut = 0;
        $rows = LinkSuggester::suggest([['id' => 1, 'topic' => 'free tomato seeds', 'cluster_id' => null, 'phrases' => ['free tomato seeds'], 'demand' => 100.0]], $paragraphs, $pages, [], [], $leftOut);
        self::assertSame([3], array_column($rows, 'source_page_id'));
        self::assertSame(1, $leftOut);
    }
}
