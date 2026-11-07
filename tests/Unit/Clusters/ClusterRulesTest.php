<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Clusters;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Clusters\ClusterMatcher;
use SeoControlCenter\Domain\Clusters\OverlapClassifier;
use SeoControlCenter\Domain\Clusters\Ownership;
use SeoControlCenter\Domain\Clusters\PageKey;

final class ClusterRulesTest extends TestCase
{
    public function testOwnershipFromSearchConsoleClicksImpressionsAndRankings(): void
    {
        $members = ['running shoes', 'best running shoes'];
        $rows = [
            ['query' => 'running shoes', 'url' => 'https://s.test/running', 'clicks' => 40, 'impressions' => 900, 'position' => 4.2],
            ['query' => 'best running shoes', 'url' => 'https://s.test/running', 'clicks' => 20, 'impressions' => 500, 'position' => 6.0],
            ['query' => 'best running shoes', 'url' => 'https://s.test/blog/best', 'clicks' => 10, 'impressions' => 400, 'position' => 9.0],
            ['query' => 'trail shoes', 'url' => 'https://s.test/trail', 'clicks' => 99, 'impressions' => 999, 'position' => 1.0],
        ];
        $owned = Ownership::infer($members, $rows, []);
        self::assertSame('owned', $owned['status']);
        self::assertSame('https://s.test/running', $owned['url']);
        self::assertSame(0.857, $owned['candidates'][0]['share'], '60 of 70 clicks; other topics are ignored.');
        self::assertSame(4.2, $owned['candidates'][0]['best_position']);
        self::assertStringContainsString('86 % of the clicks', $owned['reason']);

        $close = [...$rows, ['query' => 'running shoes', 'url' => 'https://s.test/blog/best', 'clicks' => 30, 'impressions' => 300, 'position' => 5.0]];
        $contested = Ownership::infer($members, $close, []);
        self::assertSame('contested', $contested['status'], '60 vs 40 clicks: the runner-up has at least 60 % of the leader.');

        $fewClicks = Ownership::infer(['running shoes'], [['query' => 'running shoes', 'url' => 'https://s.test/a', 'clicks' => 2, 'impressions' => 900, 'position' => 12.0], ['query' => 'running shoes', 'url' => 'https://s.test/b', 'clicks' => 3, 'impressions' => 100, 'position' => 30.0]], []);
        self::assertSame('https://s.test/a', $fewClicks['url'], 'Under 10 clicks, impressions decide.');
        self::assertStringContainsString('impressions', $fewClicks['reason']);

        $ranked = Ownership::infer($members, [], [['keyword' => 'Running Shoes', 'url' => 'https://s.test/r', 'position' => 7], ['keyword' => 'best running shoes', 'url' => 'https://s.test/r', 'position' => 12], ['keyword' => 'best running shoes', 'url' => null, 'position' => null]]);
        self::assertSame(['owned', 'https://s.test/r', 'rankings'], [$ranked['status'], $ranked['url'], $ranked['source']]);

        $none = Ownership::infer($members, [], []);
        self::assertSame(['unowned', null, []], [$none['status'], $none['url'], $none['candidates']]);
    }

    public function testPaginationVariantsAreOnePage(): void
    {
        self::assertSame('https://s.test/flavours/lemon', PageKey::of('https://s.test/flavours/lemon?page=71'));
        self::assertSame('https://s.test/brands/x?sort=new', PageKey::of('https://s.test/brands/x?sort=new&Page=3'));
        self::assertSame('https://s.test/blog/', PageKey::of('https://s.test/blog/page/4/'));
        self::assertSame('https://s.test/shop?color=red', PageKey::of('https://s.test/shop?color=red'), 'Other parameters stay.');
        self::assertSame('https://s.test/', PageKey::of('https://s.test'));

        $rows = [
            ['query' => 'lemon flavour', 'url' => PageKey::of('https://s.test/flavours/lemon?page=71'), 'clicks' => 3, 'impressions' => 40, 'position' => 9.0],
            ['query' => 'lemon flavour', 'url' => PageKey::of('https://s.test/flavours/lemon?page=70'), 'clicks' => 3, 'impressions' => 30, 'position' => 8.0],
        ];
        $owned = Ownership::infer(['lemon flavour'], $rows, []);
        self::assertSame(['owned', 'https://s.test/flavours/lemon'], [$owned['status'], $owned['url']], 'Two pages of one listing own the topic together.');
    }

    public function testTooLittleDataNamesNoOwner(): void
    {
        $rows = [
            ['query' => 'lemon flavour', 'url' => 'https://s.test/a', 'clicks' => 0, 'impressions' => 11, 'position' => 40.0],
            ['query' => 'lemon flavour', 'url' => 'https://s.test/b', 'clicks' => 0, 'impressions' => 7, 'position' => 45.0],
        ];
        $weak = Ownership::infer(['lemon flavour'], $rows, []);
        self::assertSame(['unowned', null], [$weak['status'], $weak['url']]);
        self::assertSame('https://s.test/a', $weak['candidates'][0]['url'], 'The leading page stays visible.');
        self::assertStringContainsString('0 clicks and 18 impressions', $weak['reason']);
        $enough = Ownership::infer(['lemon flavour'], [...$rows, ['query' => 'lemon flavour', 'url' => 'https://s.test/a', 'clicks' => 5, 'impressions' => 10, 'position' => 9.0]], []);
        self::assertSame('owned', $enough['status'], 'Five clicks are enough.');
    }

    public function testOverlapKinds(): void
    {
        $week = static fn(int $i): string => (new \DateTimeImmutable('2026-08-03'))->modify('+' . (7 * $i) . ' days')->format('Y-m-d');
        $row = static fn(int $w, string $url, int $impressions, float $position): array => ['week' => $week($w), 'url' => $url, 'clicks' => 1, 'impressions' => $impressions, 'position' => $position];

        $switching = OverlapClassifier::classify('trail shoes', [$row(0, 'https://s.test/a', 50, 8), $row(0, 'https://s.test/b', 20, 12), $row(1, 'https://s.test/b', 60, 7), $row(1, 'https://s.test/a', 10, 15), $row(2, 'https://s.test/a', 70, 6), $row(2, 'https://s.test/b', 10, 14)]);
        self::assertSame(['switching', true, 2], [$switching['kind'] ?? null, $switching['harmful'] ?? null, $switching['switches'] ?? null]);

        $stacked = OverlapClassifier::classify('red shoes', [$row(0, 'https://s.test/a', 50, 2), $row(0, 'https://s.test/b', 30, 4), $row(1, 'https://s.test/a', 50, 2), $row(1, 'https://s.test/b', 30, 5), $row(2, 'https://s.test/a', 50, 2), $row(2, 'https://s.test/b', 30, 4)]);
        self::assertSame(['stacked', false], [$stacked['kind'] ?? null, $stacked['harmful'] ?? null]);

        $split = OverlapClassifier::classify('blue shoes', [$row(0, 'https://s.test/a', 50, 9), $row(1, 'https://s.test/a', 50, 9), $row(2, 'https://s.test/a', 50, 9), $row(2, 'https://s.test/b', 40, 25)]);
        self::assertNotNull($split);
        self::assertSame(['split', null], [$split['kind'], $split['harmful']]);

        $brand = OverlapClassifier::classify('acme shoes', [$row(0, 'https://s.test/', 50, 1), $row(0, 'https://s.test/shoes', 50, 2), $row(1, 'https://s.test/shoes', 60, 1), $row(1, 'https://s.test/', 10, 2), $row(2, 'https://s.test/', 60, 1)], ['acme']);
        self::assertSame(['brand', false], [$brand['kind'] ?? null, $brand['harmful'] ?? null], 'Brand searches with the home page are harmless, even when they switch.');

        self::assertNull(OverlapClassifier::classify('tiny', [$row(0, 'https://s.test/a', 20, 1), $row(1, 'https://s.test/b', 20, 1), $row(2, 'https://s.test/a', 5, 1)]), 'Under 50 impressions.');
        self::assertNull(OverlapClassifier::classify('one page', [$row(0, 'https://s.test/a', 90, 1), $row(1, 'https://s.test/a', 90, 1), $row(2, 'https://s.test/a', 90, 1), $row(2, 'https://s.test/b', 5, 9)]), 'The second page has under 10 %.');
        self::assertNull(OverlapClassifier::classify('short', [$row(0, 'https://s.test/a', 90, 1), $row(0, 'https://s.test/b', 90, 1)]), 'Fewer than 3 weeks.');
    }

    public function testMatcherKeepsIdsForMostlyTheSameMembers(): void
    {
        $previous = ['id-shoes' => ['running shoes', 'best running shoes', 'running shoes men'], 'id-socks' => ['wool socks', 'running socks']];
        $current = [['running shoes', 'best running shoes', 'running shoes women'], ['hiking boots', 'trail boots'], ['running socks', 'wool socks', 'socks for running']];
        self::assertSame([0 => 'id-shoes', 2 => 'id-socks'], ClusterMatcher::match($previous, $current));
        self::assertSame([], ClusterMatcher::match(['id-a' => ['a', 'b', 'c', 'd']], [['a', 'x', 'y', 'z']]), 'Jaccard under 0.5 starts a new topic.');
        self::assertSame([1 => 'id-a'], ClusterMatcher::match(['id-a' => ['a', 'b']], [['a', 'c'], ['a', 'b']]), 'Each previous ID is used once, by the best match.');
    }
}
