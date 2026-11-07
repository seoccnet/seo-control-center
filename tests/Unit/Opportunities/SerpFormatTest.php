<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\SerpFormat;
use SeoControlCenter\Domain\Opportunities\SerpLandscape;

final class SerpFormatTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: ?string, 2: string}> */
    public static function pages(): iterable
    {
        yield 'reddit thread' => ['https://www.reddit.com/r/running/comments/abc/best_shoes/', 'Best shoes? : r/running', SerpFormat::FORUM];
        yield 'old reddit' => ['https://old.reddit.com/r/running/', null, SerpFormat::FORUM];
        yield 'community subdomain' => ['https://community.shopify.com/c/topic/123', null, SerpFormat::FORUM];
        yield 'youtube' => ['https://www.youtube.com/watch?v=abc', 'How to run', SerpFormat::VIDEO];
        yield 'wikipedia' => ['https://en.wikipedia.org/wiki/Running', 'Running - Wikipedia', SerpFormat::REFERENCE];
        yield 'yelp' => ['https://www.yelp.com/biz/joes-plumbing', null, SerpFormat::LOCAL];
        yield 'amazon product' => ['https://www.amazon.com/Nike-Pegasus/dp/B0C1', 'Nike Pegasus', SerpFormat::PRODUCT];
        yield 'amazon search' => ['https://www.amazon.de/s?k=laufschuhe', 'Amazon.de: Laufschuhe', SerpFormat::CATEGORY];
        yield 'calculator on amazon is a product' => ['https://www.amazon.com/TI-84/dp/B0001', 'TI-84 Graphing Calculator', SerpFormat::PRODUCT];
        yield 'shop product' => ['https://shop.test/products/red-shoe', 'Red Shoe – Shop', SerpFormat::PRODUCT];
        yield 'shop collection' => ['https://shop.test/collections/running-shoes', 'Running shoes', SerpFormat::CATEGORY];
        yield 'review listicle' => ['https://mag.test/gear/shoes', '10 Best Running Shoes of 2026, Tested', SerpFormat::REVIEW];
        yield 'versus path' => ['https://mag.test/asana-vs-trello', null, SerpFormat::REVIEW];
        yield 'blog post' => ['https://site.test/blog/how-to-tie-shoes', 'How to tie shoes', SerpFormat::ARTICLE];
        yield 'dated post' => ['https://site.test/2026/03/marathon-plan/', null, SerpFormat::ARTICLE];
        yield 'medium' => ['https://medium.com/@me/my-story-123', null, SerpFormat::ARTICLE];
        yield 'tool title' => ['https://bank.test/mortgage', 'Mortgage Calculator | Bank', SerpFormat::TOOL];
        yield 'tool path' => ['https://seo.test/word-counter', null, SerpFormat::TOOL];
        yield 'kitchen counter is not a tool' => ['https://home.test/kitchen-counter-ideas', 'Kitchen counter stools', SerpFormat::OTHER];
        yield 'home page' => ['https://brand.test/', 'Brand', SerpFormat::HOME];
        yield 'localized home' => ['https://brand.test/de-at/', null, SerpFormat::HOME];
        yield 'service' => ['https://agency.test/services/seo', 'SEO services', SerpFormat::SERVICE];
        yield 'unknown' => ['https://site.test/xyz-123', 'Something', SerpFormat::OTHER];
    }

    #[DataProvider('pages')]
    public function testClassify(string $url, ?string $title, string $format): void
    {
        self::assertSame($format, SerpFormat::classify($url, $title));
    }

    public function testCompatibleFormats(): void
    {
        self::assertTrue(SerpFormat::compatible(SerpFormat::ARTICLE, SerpFormat::REFERENCE));
        self::assertTrue(SerpFormat::compatible(SerpFormat::SERVICE, SerpFormat::HOME));
        self::assertFalse(SerpFormat::compatible(SerpFormat::ARTICLE, SerpFormat::CATEGORY));
        self::assertFalse(SerpFormat::compatible(SerpFormat::PRODUCT, SerpFormat::CATEGORY), 'A product page does not satisfy a search for a range.');
        self::assertSame('an article or guide', SerpFormat::withArticle(SerpFormat::ARTICLE));
        self::assertSame('a product page', SerpFormat::withArticle(SerpFormat::PRODUCT));
    }

    public function testDominantFormatNeedsHalfOfTheClassifiedResultsAndThreeOfThem(): void
    {
        $categories = self::serp(['https://a.test/collections/x', 'https://b.test/category/x', 'https://c.test/shop/x', 'https://d.test/blog/x', 'https://e.test/xyz']);
        self::assertSame(SerpFormat::CATEGORY, $categories->dominantFormat());
        self::assertSame('3 × category or listing page, 1 × article or guide (of 5 results)', $categories->describeFormats());

        $mixed = self::serp(['https://a.test/collections/x', 'https://b.test/category/x', 'https://d.test/blog/x', 'https://e.test/blog/y']);
        self::assertNull($mixed->dominantFormat(), 'Two of four is no majority of three.');

        $tooFew = self::serp(['https://a.test/collections/x', 'https://b.test/category/x', 'https://z.test/1', 'https://z.test/2']);
        self::assertNull($tooFew->dominantFormat(), 'Unclassified results never count, and two results are too few.');
    }

    public function testWeakestCompetitorIgnoresTheProjectAndUnknownDomains(): void
    {
        $serp = self::serp(['https://www.acme.test/a', 'https://big.test/b', 'https://small.test/c', 'https://www.reddit.com/r/x/']);
        $weakest = $serp->weakestCompetitor(['acme.test' => 1, 'big.test' => 80, 'small.test' => 12], 'acme.test');
        self::assertSame(['domain' => 'small.test', 'strength' => 12, 'position' => 3, 'known' => 2], $weakest);
        self::assertNull($serp->weakestCompetitor([], 'acme.test'));
        self::assertSame(1, $serp->forumResults());
        self::assertSame(['position' => 1, 'domain' => 'www.acme.test', 'url' => 'https://www.acme.test/a', 'title' => null], $serp->resultOf('acme.test'));
        self::assertSame(['acme.test', 'big.test', 'small.test', 'reddit.com'], $serp->domains());
    }

    /** @param list<string> $urls */
    private static function serp(array $urls): SerpLandscape
    {
        $results = [];
        foreach ($urls as $i => $url) {
            $results[] = ['position' => $i + 1, 'domain' => (string) parse_url($url, PHP_URL_HOST), 'url' => $url, 'title' => null];
        }

        return new SerpLandscape('kw', '2026-10-09', $results, [], false);
    }
}
