<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Comparison;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Comparison\OutlineExtractor;
use SeoControlCenter\Domain\Comparison\PageTypeClassifier;
use SeoControlCenter\Domain\Opportunities\KeywordClusterer;

/**
 * Fixes from the 0.19.0 live check (docs/audits/why-outrank-live-2026-10-16.md):
 * store listings and shop home pages are not product pages, WooCommerce
 * category addresses are categories, and head terms made of very common
 * words still form topics through their results pages.
 */
final class ListingsAndHeadTermsTest extends TestCase
{
    public function testListingsAndShopHomePagesAreNotProductPages(): void
    {
        $cards = str_repeat('<li class="product type-product"><h3>Cultivar</h3><span class="price">9.99</span><a href="?add-to-cart=1">Add to cart</a></li>', 8);
        $listing = '<!doctype html><html><head><title>Organic tomato seeds</title><script type="application/ld+json">{"@type":"CollectionPage"}</script></head><body><h1>Organic tomato seeds</h1><ul>' . $cards . '</ul><p>' . str_repeat('seeds for every grower ', 40) . '</p></body></html>';
        $page = OutlineExtractor::extract($listing, 'https://meadow.test/33-organic-tomato-seeds');
        self::assertTrue($page->elements['grid'], 'Many prices and add-to-cart buttons make a product grid.');
        $type = PageTypeClassifier::classify('https://meadow.test/33-organic-tomato-seeds', null, $page);
        self::assertSame('category', $type['type'], 'CollectionPage schema with prices and carts is a listing.');
        self::assertContains('CollectionPage schema', $type['signals']);

        $noSchema = OutlineExtractor::extract(str_replace('{"@type":"CollectionPage"}', '{"@type":"Organization"}', $listing), 'https://shop.test/seeds');
        self::assertSame('category', PageTypeClassifier::classify('https://shop.test/seeds', null, $noSchema)['type'], 'A product grid without one product\'s schema is a listing.');

        $home = PageTypeClassifier::classify('https://strng.test/', null, OutlineExtractor::extract($listing, 'https://strng.test/'));
        self::assertSame('homepage', $home['type'], 'A shop home page with products stays a home page.');
        self::assertSame('home page address', $home['signals'][0]);

        $related = str_repeat('<div class="product-card"><span class="price">5</span><button>Add to cart</button></div>', 4);
        $product = OutlineExtractor::extract(ComparisonRulesTest::product('Blue Dream Seeds') . $related, 'https://shop.test/blue-dream');
        self::assertSame('product', PageTypeClassifier::classify('https://shop.test/blue-dream', null, $product)['type'], 'A product page with related products stays a product page.');

        self::assertSame('category', PageTypeClassifier::classify('https://seedshere.test/product-category/regular-tomato-seeds/', null)['type']);
        self::assertSame('category', PageTypeClassifier::classify('https://shop.test/product-tag/auto/', null)['type']);
    }

    public function testRootAddressesArticleSchemaAndAddressPatterns(): void
    {
        $cards = str_repeat('<div class="product-card"><span class="price">5</span><button>Add to cart</button></div>', 6);
        $shop = OutlineExtractor::extract('<html><head><title>Shop tomato seeds</title></head><body><h1>Seeds</h1>' . $cards . '<p>' . str_repeat('seeds ', 80) . '</p></body></html>', 'https://valley.test/');
        foreach (['https://valley.test/', 'https://meadow.test/us/', 'https://meadow.test/en-gb/'] as $url) {
            self::assertSame('homepage', PageTypeClassifier::classify($url, 'Shop tomato seeds', $shop)['type'], $url . ': the root decides over a shop-like title (0.19.1 recheck).');
            self::assertSame('homepage', PageTypeClassifier::classify($url, 'Shop tomato seeds')['type'], $url . ' unread');
        }

        $glossary = OutlineExtractor::extract('<html><head><title>Triploids explained</title><script type="application/ld+json">{"@type":"BlogPosting"}</script></head><body><h1>Triploids</h1><span class="price">9</span><button>Add to cart</button><p>' . str_repeat('triploid plants have three sets ', 40) . '</p></body></html>', 'https://farm.test/glossary/triploids');
        self::assertSame('article', PageTypeClassifier::classify('https://farm.test/glossary/triploids', null, $glossary)['type'], 'Article schema wins over a price and cart in the shop layout.');

        self::assertSame('article', PageTypeClassifier::classify('https://gtr.test/blogs/grow-guides/seedless-triploids', null)['type'], 'Shopify blog addresses are articles.');
        self::assertSame('forum', PageTypeClassifier::classify('https://overgrow.test/t/triploidy-vs-trifoliate/25546', null)['type'], 'Discourse threads are forums.');
        self::assertSame(['an article', 'a product page'], [\SeoControlCenter\Domain\Comparison\ResultsFormat::a('article'), \SeoControlCenter\Domain\Comparison\ResultsFormat::a('product')]);
    }

    public function testTrackedKeywordsSurviveTheDemandCut(): void
    {
        $queries = [];
        for ($i = 0; $i < 2100; ++$i) {
            $queries[] = new \SeoControlCenter\Domain\SearchConsole\DimensionRow('query ' . $i, new \SeoControlCenter\Domain\SearchConsole\Metrics(0, 5000 + $i, 9.0));
        }
        $tracked = [new \SeoControlCenter\Domain\Rankings\RankedKeyword('0192f0a0-0000-7000-8000-0000000000a4', 'Tomato  Seeds', \SeoControlCenter\Domain\Keywords\KeywordLocale::default(), '2026-10-12', 40, null, null, 40, null, [], 10)];
        $demand = (new \ReflectionMethod(\SeoControlCenter\Modules\Opportunities\OpportunityAnalyzer::class, 'demand'))->invoke(null, new \SeoControlCenter\Domain\Opportunities\ProjectSignals(keywords: $tracked, queries: $queries));
        self::assertIsArray($demand);
        self::assertArrayHasKey('tomato seeds', $demand, 'A tracked keyword with little volume is kept, normalized, next to 2,100 bigger searches (0.19.1 recheck).');
        self::assertCount(2001, $demand);
    }

    public function testCommonWordHeadTermsJoinThroughSharedResults(): void
    {
        $keywords = ['tomato seeds' => 9000.0, 'organic seeds' => 4000.0, 'sunflower' => 3000.0, 'triploid' => 500.0];
        for ($i = 0; $i < 160; ++$i) {
            $keywords['cultivar' . $i . ' tomato seeds'] = 10.0;
        }
        /** @return list<string> */
        $urls = static fn(string ...$paths): array => array_values(array_map(static fn(string $p): string => 'shop.test/' . $p, $paths));
        $topics = (new KeywordClusterer())->cluster($keywords, [
            'tomato seeds' => $urls('a', 'b', 'c', 'd', 'e'),
            'organic seeds' => $urls('a', 'b', 'c', 'x', 'y'),
            'sunflower' => $urls('a', 'b', 'c', 'q', 'r'),
            'triploid' => $urls('k', 'l', 'm', 'n', 'o'),
        ]);
        self::assertSame('tomato seeds', $topics['organic seeds']['label'], 'Words used by over 150 searches are skipped, but three shared results still join.');
        self::assertSame('tomato seeds', $topics['sunflower']['label'], 'One-word searches join through their results pages.');
        self::assertSame(1, $topics['triploid']['size'], 'Different results stay apart.');
    }
}
