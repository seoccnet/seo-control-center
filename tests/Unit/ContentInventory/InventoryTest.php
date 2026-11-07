<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\ContentInventory;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\ContentInventory\Inventory;
use SeoControlCenter\Domain\ContentInventory\UrlKey;

/** The content inventory join (ADR-042): keys, states, overlap checks and uncertain matches. */
final class InventoryTest extends TestCase
{
    public function testUrlKeysKeepMeaningfulParametersAndDropNoise(): void
    {
        self::assertSame('shop.test/blog/drying', UrlKey::of('HTTPS://Shop.Test/blog/drying/?utm_source=x&gclid=1#top'));
        self::assertSame(UrlKey::of('http://shop.test/blog/drying'), UrlKey::of('https://shop.test/blog/drying/'), 'http and https, with or without a trailing slash, are one page.');
        self::assertSame('shop.test/seeds?color=red&page=2', UrlKey::of('https://shop.test/seeds?page=2&color=red&utm_medium=mail'), 'Content parameters stay, sorted.');
        self::assertNotSame(UrlKey::of('https://www.shop.test/a'), UrlKey::of('https://shop.test/a'), 'www and non-www stay apart; a redirect joins them.');
        self::assertSame('shop.test:8080/', UrlKey::of('https://shop.test:8080'));
        self::assertNull(UrlKey::of('ftp://shop.test/a'));
        self::assertSame('how to dry pepper', UrlKey::slugWords('https://shop.test/blog/how-to-dry_pepper.html'));
    }

    public function testADraftWithoutRankingsIsFoundBeforeNewContentIsRecommended(): void
    {
        $rows = Inventory::build([
            self::record('cms', null, 'item-1', 'How to dry and cure pepper', 'draft', keyword: 'How to dry and cure pepper'),
            self::record('crawl', 'https://shop.test/seeds/brandywine', 'c1', 'Brandywine seeds', 'ok'),
        ]);
        $draft = self::row($rows, 'record:cms:item-1');
        self::assertSame('draft', $draft['state'], 'A draft is not a public page.');

        $found = Inventory::covering($rows, 'dry pepper');
        self::assertCount(1, $found, 'The draft covers the idea although nothing ranks for it.');
        self::assertSame('How to dry and cure pepper', $found[0]['title']);
        self::assertSame([], Inventory::covering($rows, 'mold on pepper'));
    }

    public function testShopPagesAreOnTheTopicButDoNotCoverAnArticleSearch(): void
    {
        $rows = Inventory::build([
            self::record('crawl', 'https://shop.test/seeds/mortgage-lifter-royal-queen-seeds', 'p', 'Mortgage lifter Seeds | Meadow Seeds', 'ok'),
            self::record('crawl', 'https://shop.test/blog/mortgage-lifter-cultivar-guide', 'a', 'Mortgage lifter Cultivar: Effects and History', 'ok'),
        ]);
        $found = Inventory::covering($rows, 'mortgage lifter');
        self::assertSame([true, false], array_column($found, 'article'), 'The article page covers it; the product page is listed apart, after it.');
        self::assertSame('https://shop.test/blog/mortgage-lifter-cultivar-guide', $found[0]['url']);
        self::assertSame([false], array_column(Inventory::covering([$rows[0]], 'mortgage lifter'), 'article'), 'A product page alone is never written content.');
    }

    public function testATopicPlanOrOwnerDoesNotLendItsKeywordToItsPages(): void
    {
        $rows = Inventory::build([
            self::record('crawl', 'https://shop.test/blog/best-outdoor-tomato-cultivars', 'o', 'Best Outdoor Tomato Cultivars', 'ok'),
            self::record('topic_plan', 'https://shop.test/blog/best-outdoor-tomato-cultivars', 'plan', null, 'member', keyword: 'mold on pepper'),
            self::record('topic_owner', 'https://shop.test/blog/best-outdoor-tomato-cultivars', 'owner', null, 'owner', keyword: 'mold resistant cultivars'),
            self::record('brief', 'https://shop.test/blog/mold-guide', 'b', 'Brief: guide', 'draft', keyword: 'mold on pepper'),
        ]);
        self::assertSame(['https://shop.test/blog/mold-guide'], array_column(Inventory::covering($rows, 'mold on pepper'), 'url'), 'Only the brief, which describes its own page, covers the topic.');
    }

    public function testEachSourceKeepsItsOwnStatusAndACanonicalIsNotARedirect(): void
    {
        $rows = Inventory::build([
            self::record('crawl', 'https://shop.test/a', 'a', 'A', 'ok', target: 'https://shop.test/b'),
            self::record('crawl', 'https://shop.test/old', 'o', null, 'redirect', target: 'https://shop.test/new'),
            self::record('crawl', 'https://shop.test/b/', 'b', 'B', 'ok', target: 'https://shop.test/b'),
            self::record('search_console', 'http://shop.test/b', 'b2', null, 'impressions'),
            self::record('cms', 'https://shop.test/gone', 'g', 'Gone', 'retired'),
            self::record('sitemap', 'https://shop.test/listed', 'l', null, 'listed'),
            self::record('search_console', 'https://shop.test/unseen-by-crawl', 's', null, 'impressions'),
        ]);
        self::assertSame('canonicalised', self::row($rows, 'shop.test/a')['state']);
        self::assertSame('redirect', self::row($rows, 'shop.test/old')['state']);
        $b = self::row($rows, 'shop.test/b');
        self::assertSame('live', $b['state'], 'A self-canonical page is live.');
        self::assertSame(['crawl', 'search_console'], array_column($b['sources'], 'source'), 'Both sources stay, each with its own record.');
        self::assertSame(['http://shop.test/b'], $b['variants']);
        self::assertSame('retired', self::row($rows, 'shop.test/gone')['state']);
        self::assertSame('sitemap', self::row($rows, 'shop.test/listed')['state']);
        self::assertSame('seen', self::row($rows, 'shop.test/unseen-by-crawl')['state']);
    }

    public function testRecordsWithoutAnAddressGetCandidatesButNeverAnAutomaticMatch(): void
    {
        $rows = Inventory::build([
            self::record('cms', null, 'item-2', 'Best mold resistant cultivars', 'draft'),
            self::record('crawl', 'https://shop.test/blog/topics/mold-resistant-pepper-cultivars', 'm', 'Mold resistant pepper cultivars', 'ok'),
            self::record('crawl', 'https://shop.test/seeds/brandywine', 'g', 'Brandywine seeds', 'ok'),
        ]);
        $draft = self::row($rows, 'record:cms:item-2');
        self::assertSame('draft', $draft['state']);
        self::assertSame(['https://shop.test/blog/topics/mold-resistant-pepper-cultivars'], array_column($draft['candidates'], 'url'));
        self::assertCount(3, $rows, 'The draft and the page stay separate rows until a person links them.');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private static function row(array $rows, string $key): array
    {
        foreach ($rows as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        self::fail('No row ' . $key);
    }

    /** @return array{source: string, url: ?string, ref: string, title: ?string, status: string, observed_at: ?string, detail: string, keyword?: ?string, target?: ?string} */
    private static function record(string $source, ?string $url, string $ref, ?string $title, string $status, ?string $keyword = null, ?string $target = null): array
    {
        return ['source' => $source, 'url' => $url, 'ref' => $ref, 'title' => $title, 'status' => $status, 'observed_at' => '2026-10-20', 'detail' => $status, 'keyword' => $keyword, 'target' => $target];
    }
}
