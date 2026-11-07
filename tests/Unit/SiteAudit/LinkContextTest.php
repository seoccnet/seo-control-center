<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\InternalLinks\LinkSuggester;
use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;

/** ADR-040, F1: link context from crawled HTML and rule-based suggestions. */
final class LinkContextTest extends TestCase
{
    public function testAnchorsPlacesParagraphsAndBodyPreference(): void
    {
        $html = '<html><body><header><nav><a href="/seeds">Seeds</a><a href="/about">About</a></nav></header>'
            . '<main><p>Growing sunflowers outdoors is easy if you pick the right <a href="/seeds">sunflower seeds</a> and start early in spring.</p>'
            . '<ul><li>Short</li><li>Organic seeds give you strong plants, which most home growers in colder climates prefer.</li></ul>'
            . '<p><a href="/photo"><img src="x.jpg" alt="Photo of blossoms"></a></p><div role="navigation"><a href="/menu">Menu link</a></div></main>'
            . '<aside><p>Sidebar text that is long enough to count as a paragraph but sits outside the main text.</p></aside>'
            . '<footer><a href="/about">About us</a></footer></body></html>';
        $page = HtmlPage::parse($html, 'https://shop.test/guide');

        self::assertSame(['anchor' => 'sunflower seeds', 'place' => 'body', 'context' => 'Growing sunflowers outdoors is easy if you pick the right sunflower seeds and start early in spring.', 'class' => 'editorial', 'confidence' => 'high', 'signal' => 'inside a paragraph of text'], $page->linkContexts['https://shop.test/seeds'], 'The body link wins over the earlier menu link.');
        self::assertSame(['anchor' => 'About', 'place' => 'nav', 'context' => null, 'class' => 'template', 'confidence' => 'high', 'signal' => 'page region: nav'], $page->linkContexts['https://shop.test/about']);
        self::assertSame('Photo of blossoms', $page->linkContexts['https://shop.test/photo']['anchor'], 'An image link takes its alt text.');
        self::assertSame('nav', $page->linkContexts['https://shop.test/menu']['place'], 'role="navigation" counts as a menu.');
        self::assertSame([
            'Growing sunflowers outdoors is easy if you pick the right sunflower seeds and start early in spring.',
            'Organic seeds give you strong plants, which most home growers in colder climates prefer.',
        ], $page->paragraphs, 'Short items and asides are not paragraphs.');
    }

    public function testLongParagraphsAreCutAroundTheAnchor(): void
    {
        $before = str_repeat('Lorem ipsum dolor sit amet. ', 30);
        $page = HtmlPage::parse('<p>' . $before . '<a href="/x">target words</a> and the rest of the sentence.</p>', 'https://shop.test/');
        $context = (string) $page->linkContexts['https://shop.test/x']['context'];
        self::assertStringContainsString('target words', $context);
        self::assertStringStartsWith('…', $context);
        self::assertLessThanOrEqual(HtmlPage::PARAGRAPH_LENGTH, mb_strlen($context));
    }

    public function testSuggestionsSkipLinkedSelfAndSingleWordsAndRespectLimits(): void
    {
        $pages = [1 => ['url' => 'https://s/a', 'inbound' => 10], 2 => ['url' => 'https://s/b', 'inbound' => 3], 3 => ['url' => 'https://s/c', 'inbound' => 1], 4 => ['url' => 'https://s/d', 'inbound' => 50]];
        $paragraphs = [
            ['page_id' => 2, 'text' => 'Most growers start with Sunflower Seeds because they finish fast.'],
            ['page_id' => 3, 'text' => 'Our sunflower seed range is linked already.'],
            ['page_id' => 1, 'text' => 'sunflower seeds on the target itself'],
            ['page_id' => 4, 'text' => 'Seeds, sunflower and more: words out of order do not match.'],
            ['page_id' => 9, 'text' => 'sunflower seeds on a page that is not indexable'],
        ];
        $target = ['id' => 1, 'topic' => 'sunflower seeds', 'cluster_id' => 'c1', 'phrases' => ['sunflower seeds', 'seeds'], 'demand' => 900.0];
        $rows = LinkSuggester::suggest([$target], $paragraphs, $pages, ['3:1' => true]);
        self::assertCount(1, $rows);
        self::assertSame([2, 1, 'Sunflower Seeds', 'c1'], [$rows[0]['source_page_id'], $rows[0]['target_page_id'], $rows[0]['anchor'], $rows[0]['cluster_id']]);

        // One page mentioning many topics gives at most PER_SOURCE suggestions.
        $targets = [];
        $text = '';
        for ($i = 10; $i < 16; ++$i) {
            $pages[$i] = ['url' => 'https://s/t' . $i, 'inbound' => 1];
            $targets[] = ['id' => $i, 'topic' => 'topic' . $i . ' guide', 'cluster_id' => null, 'phrases' => ['topic' . $i . ' guide'], 'demand' => (float) $i];
            $text .= 'Read the topic' . $i . ' guide. ';
        }
        $many = LinkSuggester::suggest($targets, [['page_id' => 4, 'text' => $text]], $pages, []);
        self::assertCount(LinkSuggester::PER_SOURCE, $many);
        self::assertSame([15, 14, 13], array_column($many, 'target_page_id'), 'The strongest topics first.');
    }

    /** The wrong suggestions of the Web Shop live check (0.25.2), and the good ones that stay. */
    public function testPunctuationCompetingPagesAndDedicatedPagesAreRespected(): void
    {
        $pages = [
            1 => ['url' => 'https://s/giveaways', 'inbound' => 50],
            2 => ['url' => 'https://s/seeds/sunflower-seeds', 'inbound' => 40],
            3 => ['url' => 'https://s/seeds/triploid-seeds', 'inbound' => 5],
            4 => ['url' => 'https://s/', 'inbound' => 100],
            5 => ['url' => 'https://s/tastes/strawberry', 'inbound' => 3],
            6 => ['url' => 'https://s/rewards', 'inbound' => 2],
            7 => ['url' => 'https://s/blog/indoors', 'inbound' => 2],
        ];
        $paragraphs = [
            ['page_id' => 4, 'text' => 'Explore our seed bank to buy organic, regular and revolutionary triploid seeds selected for peak yields.'],
            ['page_id' => 3, 'text' => 'Before choosing a triploid seed pack, compare the named breeder and the seeds per pack.'],
            ['page_id' => 5, 'text' => 'Compare BigBerry with Auto Strawberry Banana by Orchard Seeds (sunflower) for a quick harvest.'],
            ['page_id' => 6, 'text' => 'Choose free tomato seeds when your cart reaches an active reward milestone.'],
            ['page_id' => 7, 'text' => 'Sunflower seeds are great for beginners because they need no light changes.'],
        ];
        $targets = [
            ['id' => 1, 'topic' => 'bulk seeds for sale', 'cluster_id' => 'c1', 'phrases' => ['bulk seeds for sale', 'triploid seeds', 'free tomato seeds'], 'demand' => 900.0, 'exclude' => [3]],
            ['id' => 2, 'topic' => 'veg seeds', 'cluster_id' => 'c2', 'phrases' => ['veg seeds', 'seeds sunflower', 'sunflower seeds'], 'demand' => 800.0],
        ];
        $dedicated = [LinkSuggester::stemKey('triploid-seeds') => 3, LinkSuggester::stemKey('sunflower-seeds') => 2];
        $rows = LinkSuggester::suggest($targets, $paragraphs, $pages, [], $dedicated);
        $pairs = array_map(static fn(array $r): string => $r['source_page_id'] . '>' . $r['target_page_id'] . ':' . $r['anchor'], $rows);
        sort($pairs);

        self::assertSame(['7>2:Sunflower seeds'], $pairs, 'No match across "Seeds (sunflower", no triploid link away from or past /seeds/triploid-seeds; since 0.35.0 the reward paragraph no longer links to the giveaway page.');
        self::assertSame('triploid seed', LinkSuggester::stemKey('Triploid-Seeds'));
        // A hyphen between the words still matches.
        $hyphen = LinkSuggester::suggest([['id' => 2, 'topic' => 'veg seeds', 'cluster_id' => null, 'phrases' => ['seeds sunflower'], 'demand' => 1.0]], [['page_id' => 7, 'text' => 'Our seeds-sunflower packs suit balconies and short northern summers.']], $pages, []);
        self::assertSame('seeds-sunflower', $hyphen[0]['anchor'] ?? null);
    }

    /** The wrong suggestions of the 0.25.3 live check: longer searches of a dedicated page and product spec lines. */
    public function testLongerSearchesOfDedicatedPagesAndSpecLinesAreSkipped(): void
    {
        $pages = [1 => ['url' => 'https://s/giveaways', 'inbound' => 50], 3 => ['url' => 'https://s/seeds/regular-seeds', 'inbound' => 5], 6 => ['url' => 'https://s/rewards', 'inbound' => 2], 8 => ['url' => 'https://s/seeds/organic-seeds', 'inbound' => 9], 9 => ['url' => 'https://s/seeds/don-carlos', 'inbound' => 1]];
        $paragraphs = [
            ['page_id' => 8, 'text' => 'Growers seeking pollen donors and wider selection usually choose regular tomato seeds.'],
            ['page_id' => 9, 'text' => 'Regular tomato seeds by Valley Seed Company SKU VSC-DOCAR-OPN'],
            ['page_id' => 6, 'text' => 'Earn points on eligible orders and choose free tomato seeds when your cart reaches a reward milestone.'],
        ];
        $target = ['id' => 1, 'topic' => 'bulk seeds for sale', 'cluster_id' => 'c1', 'phrases' => ['regular tomato seeds', 'free tomato seeds'], 'demand' => 900.0];
        $rows = LinkSuggester::suggest([$target], $paragraphs, $pages, [], [LinkSuggester::stemKey('regular-seeds') => 3]);
        self::assertSame([], array_map(static fn(array $r): string => $r['source_page_id'] . ':' . $r['anchor'], $rows), '"regular tomato seeds" belongs to /seeds/regular-seeds; the SKU line is no paragraph; "free tomato seeds" in a reward paragraph is not about the giveaway page (0.35.0).');

        // The dedicated page itself can still receive its longer search.
        $own = LinkSuggester::suggest([['id' => 3, 'topic' => 'regular seeds', 'cluster_id' => null, 'phrases' => ['regular tomato seeds'], 'demand' => 10.0]], $paragraphs, $pages, [], [LinkSuggester::stemKey('regular-seeds') => 3]);
        self::assertSame([8], array_column($own, 'source_page_id'));
    }
}
