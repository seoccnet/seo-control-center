<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\ContentInventory;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\ContentInventory\OverlapFinder;

/** Overlap review candidates (ADR-042): evidence and uncertainty, never an automatic merge. */
final class OverlapFinderTest extends TestCase
{
    private const string GUIDE = 'Mold on pepper starts in dense blossoms when humidity stays high for days. Check the inside of clusters for grey fuzz, cut affected blossoms well below the rot and lower the humidity in the grow room to stop it spreading to other plants before harvest.';
    private const string FOOTER = 'Free shipping on all orders over fifty euros and discreet packaging for every parcel sent anywhere in Europe within two working days of payment.';

    public function testNearDuplicateTextIsFoundButTemplateTextIsNot(): void
    {
        $pages = [
            ['url' => 'https://s/blog/mold-on-pepper', 'title' => 'Mold on pepper', 'text' => self::GUIDE . ' ' . self::FOOTER],
            ['url' => 'https://s/blog/blossom-rot', 'title' => 'Blossom rot', 'text' => self::GUIDE . ' Most growers notice it too late. ' . self::FOOTER],
        ];
        foreach (range(1, 12) as $i) {
            $pages[] = ['url' => 'https://s/seeds/cultivar-' . $i, 'title' => 'Cultivar ' . $i, 'text' => 'A unique description of cultivar number ' . $i . ' with its own effects, flavours and flowering time for growers. ' . self::FOOTER];
        }
        $found = self::find($pages, [], []);
        self::assertSame([OverlapFinder::DUPLICATE], array_values(array_unique(array_column($found, 'kind'))), 'The shared shipping text on every page makes no duplicates.');
        self::assertCount(1, $found);
        self::assertSame(['https://s/blog/mold-on-pepper', 'https://s/blog/blossom-rot'], $found[0]['members']);
        self::assertStringContainsString('of their body text is the same', $found[0]['evidence'][0]);
        self::assertStringContainsString('JavaScript', $found[0]['uncertainty']);
    }

    public function testTwoNearSynonymIdeasAreProposedForReviewNotMerged(): void
    {
        $found = self::find([], [], [
            ['key' => str_repeat('a', 64), 'main_keyword' => 'mold on pepper', 'kind' => 'new'],
            ['key' => str_repeat('b', 64), 'main_keyword' => 'pepper mold', 'kind' => 'new'],
            ['key' => str_repeat('c', 64), 'main_keyword' => 'brandywine cultivar', 'kind' => 'new'],
        ]);
        self::assertCount(1, $found);
        self::assertSame([OverlapFinder::SYNONYM_IDEAS, ['mold on pepper', 'pepper mold']], [$found[0]['kind'], $found[0]['members']]);
        self::assertStringContainsString('different intents', $found[0]['uncertainty']);
        self::assertSame($found[0]['key'], OverlapFinder::key(OverlapFinder::SYNONYM_IDEAS, [str_repeat('b', 64), str_repeat('a', 64)]), 'The key ignores member order, so a decision sticks.');
    }

    public function testSharedSearchesAreNotHarmfulByThemselves(): void
    {
        $stacked = ['query' => 'brandywine seeds', 'kind' => 'stacked', 'harmful' => false, 'note' => 'The same page leads every week.', 'pages' => [['url' => 'https://s/shop/brandywine', 'share' => 0.8, 'position' => 3.0], ['url' => 'https://s/blog/brandywine-cultivar', 'share' => 0.2, 'position' => 7.0]]];
        $switching = ['query' => 'mold on pepper', 'kind' => 'switching', 'harmful' => true, 'note' => 'The leading page changed 4 times.', 'pages' => [['url' => 'https://s/blog/mold-on-pepper', 'share' => 0.5, 'position' => 8.0], ['url' => 'https://s/blog/topics/mold-on-pepper', 'share' => 0.5, 'position' => 9.0]]];
        $brand = ['query' => 'northwind goods', 'kind' => 'brand', 'harmful' => false, 'note' => '', 'pages' => [['url' => 'https://s/', 'share' => 0.7, 'position' => 1.0], ['url' => 'https://s/about', 'share' => 0.3, 'position' => 2.0]]];
        $found = self::find([['url' => 'https://s/shop/brandywine', 'title' => 'Brandywine Seeds | Organic', 'text' => ''], ['url' => 'https://s/blog/brandywine-cultivar', 'title' => 'Brandywine Cultivar: Effects and History', 'text' => '']], [$stacked, $switching, $brand], []);

        self::assertCount(2, $found, 'Brand searches are left out.');
        self::assertSame(['https://s/blog/mold-on-pepper', 'https://s/blog/topics/mold-on-pepper'], $found[0]['members'], 'A switching leader ranks first.');
        $brandywine = $found[1];
        self::assertStringContainsString('No search here shows the leading page switching', $brandywine['uncertainty']);
        self::assertStringContainsString('different kinds', (string) $brandywine['distinct_purpose'], 'A product page and an article share words but answer different intents.');
    }

    public function testTopicArchivesOnOneSubjectAreFound(): void
    {
        $found = self::find([
            ['url' => 'https://s/blog/topics/mold-on-pepper', 'title' => null, 'text' => ''],
            ['url' => 'https://s/blog/topics/mold-resistant-pepper-cultivars', 'title' => null, 'text' => ''],
            ['url' => 'https://s/blog/topics/brandywine', 'title' => null, 'text' => ''],
            ['url' => 'https://s/blog/topics/mold-on-pepper?page=2', 'title' => null, 'text' => ''],
        ], [], []);
        self::assertCount(1, $found);
        self::assertSame(OverlapFinder::ARCHIVES, $found[0]['kind']);
        self::assertSame('The narrower archive "mold on pepper" is a part of "mold resistant pepper cultivars".', $found[0]['evidence'][0], 'The evidence quotes the addresses\' own words, not folded stems.');
    }

    public function testPaginatedCopiesAndArchivesAreNotNearDuplicates(): void
    {
        $listing = str_repeat('Lemon flavoured cultivars with bright citrus flavours, sorted by popularity for every grower. ', 4);
        $found = self::find([
            ['url' => 'https://s/tastes/lemon?page=5', 'title' => 'Lemon', 'text' => $listing],
            ['url' => 'https://s/tastes/lemon?page=6', 'title' => 'Lemon', 'text' => $listing],
            ['url' => 'https://s/blog/topics/t-post-trellis', 'title' => 'Trellis', 'text' => $listing],
            ['url' => 'https://s/blog/topics/topping-outdoor', 'title' => 'Topping', 'text' => $listing],
        ], [], []);
        self::assertSame([], array_column($found, 'kind'), 'Listing pages repeat their intro and post snippets; that is not duplicate content.');
    }

    public function testEachKindIsCappedOnItsOwnAndCountedBeforeTheCap(): void
    {
        $ideas = [];
        // 16 ideas with the same meaningful words ("mold", "pepper"; numbers under three characters do not count): 120 pairs.
        foreach (range(1, 16) as $i) {
            $ideas[] = ['key' => hash('sha256', 'idea' . $i), 'main_keyword' => ($i % 2 === 0 ? 'mold on pepper ' : 'pepper mold ') . $i, 'kind' => 'new'];
        }
        $archives = [['url' => 'https://s/blog/topics/mold-on-pepper', 'title' => null, 'text' => ''], ['url' => 'https://s/blog/topics/mold-resistant-pepper-cultivars', 'title' => null, 'text' => '']];
        $result = OverlapFinder::find($archives, [], $ideas);
        self::assertSame(OverlapFinder::PER_KIND, count(array_filter($result['candidates'], static fn(array $c): bool => $c['kind'] === OverlapFinder::SYNONYM_IDEAS)), 'One kind fills at most its own share.');
        self::assertGreaterThan(OverlapFinder::PER_KIND, $result['totals'][OverlapFinder::SYNONYM_IDEAS], 'The total counts every candidate, before the cap.');
        self::assertSame(1, $result['totals'][OverlapFinder::ARCHIVES]);
        self::assertContains(OverlapFinder::ARCHIVES, array_column($result['candidates'], 'kind'), 'A weaker kind is still listed.');
    }

    public function testShortAndQuestionWordsInArchiveAddressesCount(): void
    {
        $pages = [];
        foreach (['tomato-soil-ph', 'mulching-tomato-soil', 'f1-hybrid', 'hybrid-tomato', 'when-to-harvest-tomato', 'outdoor-tomato-harvest'] as $slug) {
            $pages[] = ['url' => 'https://s/blog/topics/' . $slug, 'title' => null, 'text' => ''];
        }

        self::assertSame([], self::find($pages, [], []), '"ph", "f1" and "when" carry the subject, so none of these is a part of the other.');
    }

    public function testSubtopicArchivesRankBelowArchivesOnOneSubject(): void
    {
        $pages = [];
        foreach (['yield', 'high-yield-indoor-pepper', 'trellising', 'tomato-trellising', 'tomato-curing', 'tomato-cloning'] as $slug) {
            $pages[] = ['url' => 'https://s/blog/topics/' . $slug, 'title' => null, 'text' => ''];
        }
        $found = self::find($pages, [], []);

        self::assertSame([['https://s/blog/topics/trellising', 'https://s/blog/topics/tomato-trellising'], ['https://s/blog/topics/yield', 'https://s/blog/topics/high-yield-indoor-pepper']], array_column($found, 'members'), '"tomato" is common on this site, so trellising is one subject; "high yield indoor pepper" adds its own words.');
        self::assertSame('The narrower archive "trellising" is a part of "tomato trellising".', $found[0]['evidence'][0]);
        self::assertStringNotContainsString('subtopic', $found[0]['uncertainty']);
        self::assertStringContainsString('They differ in "high", "indoor", "pepper", so one may be a subtopic', $found[1]['uncertainty']);
    }

    public function testArchivesSharingOnlyTheSitesCommonWordsAreNotOneSubject(): void
    {
        $pages = [];
        foreach (['outdoor-tomato-harvest', 'outdoor-tomato-pruning', 'outdoor-tomato-sunlight', 'outdoor-tomato-watering', 'best-outdoor-tomato-cultivars', 'mold-on-pepper', 'mold-resistant-pepper-cultivars', 'sunflower-guide'] as $slug) {
            $pages[] = ['url' => 'https://s/blog/topics/' . $slug, 'title' => null, 'text' => ''];
        }
        $pages[] = ['url' => 'https://s/authors/editor-1', 'title' => null, 'text' => self::GUIDE];
        $pages[] = ['url' => 'https://s/blog', 'title' => null, 'text' => self::GUIDE];
        $found = self::find($pages, [], []);

        self::assertSame([['https://s/blog/topics/mold-on-pepper', 'https://s/blog/topics/mold-resistant-pepper-cultivars']], array_column($found, 'members'), '"outdoor tomato" alone does not make harvest and pruning one subject; an author archive is a listing page, not duplicate text.');
    }

    /**
     * @param list<array{url: string, title: ?string, text: string}> $pages
     * @param list<array{query: string, kind: string, harmful: ?bool, note: string, pages: list<array{url: string, share: float, position: float}>}> $overlaps
     * @param list<array{key: string, main_keyword: string, kind: string}> $ideas
     * @return list<array{key: string, kind: string, members: list<string>, evidence: list<string>, uncertainty: string, distinct_purpose: ?string, score: float}>
     */
    private static function find(array $pages, array $overlaps, array $ideas): array
    {
        return OverlapFinder::find($pages, $overlaps, $ideas)['candidates'];
    }
}
