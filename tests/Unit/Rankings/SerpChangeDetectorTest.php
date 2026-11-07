<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Rankings;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Rankings\RankCheck;
use SeoControlCenter\Domain\SerpHistory\SerpChangeDetector as D;
use SeoControlCenter\Domain\SerpHistory\SerpEvent;
use SeoControlCenter\Domain\SerpHistory\SerpEventText;

/**
 * Results-page changes between two comparable checks (ADR-039, E1).
 */
final class SerpChangeDetectorTest extends TestCase
{
    public function testFeaturesCompetitorsUrlsOwnMovementAndPageType(): void
    {
        $previous = new RankCheck('2026-10-05', 12, 'https://shop.test/seeds', ['people_also_ask', 'images'], self::top([
            'https://www.seedhouse.test/tomato-seeds', 'https://forum.test/t/a/1', 'https://blog.test/guide', 'https://news.test/2026/10/x', 'https://rival.test/blog/a',
        ]));
        $current = new RankCheck('2026-10-12', 4, 'https://shop.test/seeds', ['people_also_ask', 'ai_overview'], self::top([
            'https://www.seedhouse.test/collections/seeds', 'https://other.test/category/seeds', 'https://bigshop.test/shop/seeds', 'https://shop.test/seeds', 'https://store.test/collections/x', 'https://rival2.test/c/x',
        ]));
        $events = D::compare($previous, $current, 'www.shop.test', ['seedhouse.test', 'rival.test', 'rival2.test']);
        $by = [];
        foreach ($events as $e) {
            $by[$e['kind']][] = $e['subject'];
        }
        self::assertSame(['ai_overview'], $by[D::FEATURE_ADDED]);
        self::assertSame(['images'], $by[D::FEATURE_REMOVED]);
        self::assertSame(['top10'], $by[D::OWN_ENTERED], '#12 → #4 enters the top 10.');
        self::assertSame(['rival2.test'], $by[D::COMPETITOR_ENTERED]);
        self::assertSame(['rival.test'], $by[D::COMPETITOR_LEFT]);
        self::assertSame(['https://www.seedhouse.test/collections/seeds', 'https://shop.test/seeds', 'https://rival2.test/c/x'], $by[D::NEW_URL], 'New URLs of competitors and of the project; seedhouse stayed but with another URL.');
        self::assertSame(['fragmented→category'], $by[D::FORMAT_SHIFT]);
        $entered = array_values(array_filter($events, static fn(array $e): bool => $e['kind'] === D::COMPETITOR_ENTERED))[0];
        self::assertSame(['position' => 6, 'url' => 'https://rival2.test/c/x', 'above_you' => false], $entered['detail']);
        $own = array_values(array_filter($events, static fn(array $e): bool => $e['kind'] === D::NEW_URL && ($e['detail']['own'] ?? false) === true));
        self::assertCount(1, $own);

        $event = new SerpEvent('veg seeds', 'id', 'desktop', '2026-10-12', '2026-10-05', D::FEATURE_ADDED, 'ai_overview', ['own_position' => 4]);
        self::assertSame('AI Overview appeared for “veg seeds”', SerpEventText::summary($event));
        self::assertSame('You entered the top 10 for “veg seeds”: #12 → #4', SerpEventText::summary(new SerpEvent('veg seeds', 'id', 'desktop', '2026-10-12', '2026-10-05', D::OWN_ENTERED, 'top10', ['from' => 12, 'to' => 4])));
    }

    public function testOwnMovesAndLeavingAndNoNoiseWithoutChange(): void
    {
        $same = new RankCheck('2026-10-05', 7, 'https://shop.test/a', ['images'], self::top(['https://a.test/1', 'https://b.test/2']));
        self::assertSame([], D::compare($same, new RankCheck('2026-10-06', 9, 'https://shop.test/a', ['images'], $same->top), 'shop.test', ['a.test']), 'A move of 2 places is not a change.');
        $left = D::compare($same, new RankCheck('2026-10-06', null, null, ['images'], $same->top), 'shop.test', []);
        self::assertSame([[D::OWN_LEFT, ['from' => 7, 'to' => null, 'url' => 'https://shop.test/a']]], array_map(static fn(array $e): array => [$e['kind'], $e['detail']], $left));
        $moved = D::compare(new RankCheck('2026-10-05', 40, null, [], []), new RankCheck('2026-10-06', 25, null, [], []), 'shop.test', []);
        self::assertSame([D::OWN_MOVED], array_column($moved, 'kind'), 'Outside the top 10, a move of 5 or more places counts.');
        $above = D::compare(new RankCheck('2026-10-05', 5, null, [], self::top(['https://x.test/1'])), new RankCheck('2026-10-06', 5, null, [], self::top(['https://rival.test/p', 'https://x.test/1'])), 'shop.test', ['rival.test']);
        self::assertTrue(array_values(array_filter($above, static fn(array $e): bool => $e['kind'] === D::COMPETITOR_ENTERED))[0]['detail']['above_you']);
    }

    /**
     * @param list<string> $urls
     * @return list<array{position: int, domain: string, url: string}>
     */
    private static function top(array $urls): array
    {
        return array_map(static fn(int $i, string $u): array => ['position' => $i + 1, 'domain' => (string) parse_url($u, PHP_URL_HOST), 'url' => $u], array_keys($urls), $urls);
    }
}
