<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Pages;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Pages\PageImprovements;

final class PageImprovementsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function evidence(): array
    {
        return ['issues' => [], 'traffic_comparison' => ['available' => false, 'previous_google' => null],
            'search_queries' => [], 'rankings' => [], 'internal_link_suggestions' => [], 'cannibalization' => [], 'opportunities' => []];
    }

    public function testErrorsPrecedeMeaningfulDeclinesAndRankingGains(): void
    {
        $details = $this->evidence();
        $details['issues'] = [['severity' => 'warning', 'label' => 'Missing description', 'detail' => null, 'advice' => 'Write a helpful description.'],
            ['severity' => 'error', 'label' => 'Server error', 'detail' => 'HTTP 500', 'advice' => 'Fix the server response.']];
        $details['traffic_comparison'] = ['available' => true, 'previous_google' => ['clicks' => 100], 'previous_range' => ['from' => '2026-08-05', 'to' => '2026-09-01']];
        $details['range'] = ['from' => '2026-09-02', 'to' => '2026-09-29'];
        $details['google'] = ['clicks' => 70];
        $details['search_queries'] = [['query' => 'seo tools', 'position' => 11.0, 'impressions' => 50]];
        $items = (new PageImprovements())->recommend($details);
        self::assertSame(['audit_error', 'traffic_decline', 'ranking_gains', 'audit_warning'], array_column($items, 'id'));
        self::assertSame(['Server error: HTTP 500'], $items[0]['evidence']);
        self::assertSame('Fix the server response.', $items[0]['next_action']);
        self::assertStringContainsString('down 30.0%', $items[1]['evidence'][0]);
    }

    public function testMissingSmallOrStableTrafficDoesNotBecomeADecline(): void
    {
        $details = $this->evidence();
        self::assertSame([], (new PageImprovements())->recommend($details));
        $details['traffic_comparison'] = ['available' => true, 'previous_google' => ['clicks' => 99]];
        $details['google'] = ['clicks' => 0];
        self::assertSame([], (new PageImprovements())->recommend($details));
        $details['traffic_comparison']['previous_google']['clicks'] = 100;
        $details['google']['clicks'] = 71;
        self::assertSame([], (new PageImprovements())->recommend($details));
        $details['google']['clicks'] = 120;
        self::assertSame([], (new PageImprovements())->recommend($details));
    }

    public function testLowEvidenceQueriesAreExcludedAndTrackedKeywordsUseTheirOwnSection(): void
    {
        $details = $this->evidence();
        $details['search_queries'] = [['query' => 'tiny', 'position' => 10.0, 'impressions' => 49],
            ['query' => 'winner', 'position' => 3.0, 'impressions' => 100], ['query' => 'far away', 'position' => 21.0, 'impressions' => 100]];
        self::assertSame([], (new PageImprovements())->recommend($details));
        $details['rankings'] = [['keyword' => 'tracked', 'position' => 20, 'device' => 'mobile', 'checked_on' => '2026-09-29']];
        $items = (new PageImprovements())->recommend($details);
        self::assertSame('page-rankings', $items[0]['section']);
        self::assertStringContainsString('tracked position 20', $items[0]['evidence'][0]);
    }

    public function testLinkAndIntentRecommendationsRetainTheirEvidence(): void
    {
        $details = $this->evidence();
        $details['internal_link_suggestions'] = [['url' => 'https://site.test/guide', 'inbound_links' => 8, 'matching_terms' => ['seo']]];
        $details['cannibalization'] = [['query' => 'seo', 'pages' => [['url' => 'a'], ['url' => 'b']]]];
        $items = (new PageImprovements())->recommend($details);
        self::assertSame(['internal_links', 'cannibalization'], array_column($items, 'id'));
        self::assertStringContainsString('https://site.test/guide', $items[0]['evidence'][0]);
        self::assertStringContainsString('2 pages', $items[1]['evidence'][0]);
        self::assertStringContainsString('only when their purpose overlaps', $items[1]['next_action']);
    }

    public function testOutlookEstimatesClicksAndComparesWithThePagesAbove(): void
    {
        $url = 'https://shop.acme.test/guide/';
        $model = new \SeoControlCenter\Domain\Opportunities\CtrModel([3 => ['clicks' => 20000, 'impressions' => 100000]]);
        $q = static fn(string $query, int $clicks, int $impressions, float $position): \SeoControlCenter\Modules\Pages\PageQuery => new \SeoControlCenter\Modules\Pages\PageQuery($query, $url, new \SeoControlCenter\Domain\SearchConsole\Metrics($clicks, $impressions, $position));
        $potential = \SeoControlCenter\Modules\Pages\PageOutlook::clickPotential([
            $q('seed guide', 10, 1000, 8.2), $q('thin', 0, 49, 9.0), $q('already top', 300, 1000, 2.0), $q('far', 1, 400, 21.0), $q('grow tips', 5, 200, 12.0),
        ], $model);
        $expected = (int) round(1000 * $model->expected(3));
        self::assertSame([['query' => 'seed guide', 'position' => 8.2, 'impressions' => 1000, 'clicks' => 10, 'expected' => $expected, 'gain' => $expected - 10], ['query' => 'grow tips', 'position' => 12.0, 'impressions' => 200, 'clicks' => 5, 'expected' => (int) round(200 * $model->expected(3)), 'gain' => (int) round(200 * $model->expected(3)) - 5]], $potential, 'positions 4–20 with at least 50 impressions, most gain first');

        $page = static fn(string $u, int $words, int $questions, ?string $modified = null): \SeoControlCenter\Domain\Comparison\PageStructure => new \SeoControlCenter\Domain\Comparison\PageStructure($u, 'ok', questions: array_fill(0, $questions, 'Q?'), schemaTypes: ['Article'], wordCount: $words, tables: 1, modified: $modified);
        $analysis = static fn(string $id, string $own, ?int $position, string $finished): \SeoControlCenter\Domain\Comparison\Analysis => new \SeoControlCenter\Domain\Comparison\Analysis($id, 1, 1, 'cluster-' . $id, 'Seeds', 'seed guide', ['seed guide'], 100.0, 'done', 'live', 2840, 'en', [
            ['position' => 1, 'url' => 'https://a.test/1', 'domain' => 'a.test', 'title' => null, 'type' => 'guide', 'confidence' => 'high', 'signals' => [], 'own' => false, 'fetched' => true],
            ['position' => 2, 'url' => 'https://b.test/2', 'domain' => 'b.test', 'title' => null, 'type' => 'guide', 'confidence' => 'high', 'signals' => [], 'own' => false, 'fetched' => true],
            ['position' => 3, 'url' => 'https://c.test/3', 'domain' => 'c.test', 'title' => null, 'type' => 'guide', 'confidence' => 'high', 'signals' => [], 'own' => false, 'fetched' => false],
            ['position' => 8, 'url' => $own, 'domain' => 'shop.acme.test', 'title' => null, 'type' => 'guide', 'confidence' => 'high', 'signals' => [], 'own' => true, 'fetched' => true],
            ['position' => 9, 'url' => 'https://d.test/9', 'domain' => 'd.test', 'title' => null, 'type' => 'guide', 'confidence' => 'high', 'signals' => [], 'own' => false, 'fetched' => true],
        ], $own, $position, ['https://a.test/1' => $page('https://a.test/1', 2400, 6, '2026-09-01T10:00:00Z'), 'https://b.test/2' => $page('https://b.test/2', 1600, 4), 'https://c.test/3' => \SeoControlCenter\Domain\Comparison\PageStructure::unread('https://c.test/3', 'blocked', 'robots'), $own => $page($own, 900, 0, '2025-12-01'), 'https://d.test/9' => $page('https://d.test/9', 100, 0)], [], 0.1, null, '2026-09-01 00:00:00', $finished);

        $gap = \SeoControlCenter\Modules\Pages\PageOutlook::compare([
            $analysis('old', 'https://shop.acme.test/guide', 8, '2026-09-02 00:00:00'),
            $analysis('new', 'http://www.Shop.acme.test/guide', 8, '2026-09-20 00:00:00'),
            $analysis('other', 'https://shop.acme.test/other', 2, '2026-09-30 00:00:00'),
        ], $url);
        self::assertNotNull($gap);
        self::assertSame('new', $gap['analysis'], 'the newest analysis of this page; scheme, www, host case and trailing slash do not matter');
        self::assertSame(['a.test', 'b.test'], array_column($gap['above'], 'domain'), 'only readable pages above it');
        $by = array_column($gap['measures'], null, 'key');
        self::assertSame([900, 2000.0, true], [$by['words']['own'], $by['words']['median'], $by['words']['behind']]);
        self::assertSame([0, 5.0, true], [$by['questions']['own'], $by['questions']['median'], $by['questions']['behind']]);
        self::assertSame([1, 1.0, false], [$by['tables']['own'], $by['tables']['median'], $by['tables']['behind']]);
        self::assertFalse($by['videos']['behind'], 'nothing to be behind on when the pages above have none');
        self::assertSame(['2025-12-01', '2026-09-01'], [$gap['own_modified'], $gap['newest_above']]);
        self::assertNull(\SeoControlCenter\Modules\Pages\PageOutlook::compare([$analysis('x', 'https://shop.acme.test/other', 2, '2026-09-30 00:00:00')], $url));

        $details = $this->evidence();
        $details['search_queries'] = [['query' => 'seed guide', 'position' => 8.2, 'impressions' => 1000]];
        $details['click_potential'] = $potential;
        $details['competitor_gap'] = $gap;
        $items = (new PageImprovements())->recommend($details);
        self::assertSame(['ranking_gains', 'competitor_gap'], array_column($items, 'id'));
        self::assertSame(95, $items[0]['priority'], 'a large estimated gain moves the fix up');
        self::assertStringContainsString('about ' . number_format(array_sum(array_column($potential, 'gain'))) . ' more Google clicks per 28 days', $items[0]['evidence'][0]);
        self::assertSame(['For “seed guide”, compared with 2 pages ranking above it.', 'Words: 900 here, 2,000 typical above.', 'Questions answered: 0 here, 5 typical above.'], $items[1]['evidence']);
    }
}
