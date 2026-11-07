<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\PanelSnapshot;
use SeoControlCenter\Domain\Opportunities\PlaybookLibrary;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\BingCrawlRule;
use SeoControlCenter\Domain\Opportunities\Rules\IndexingRule;
use SeoControlCenter\Domain\Opportunities\Rules\SitemapRule;
use SeoControlCenter\Domain\Opportunities\Rules\SurfaceDeclineRule;
use SeoControlCenter\Domain\Opportunities\SignalRequirements;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchType;
use SeoControlCenter\Domain\SearchConsole\Sitemap;
use SeoControlCenter\Modules\Bing\BingCrawlDay;
use SeoControlCenter\Modules\Opportunities\OpportunityAnalyzer;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBing;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOpportunities;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchConsoleExtras;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Opportunities from the Search Console and Bing extras: inspection
 * canonicals and rich results, sitemaps, Bing crawl counts and the other
 * Google surfaces.
 */
final class SearchExtrasRulesTest extends TestCase
{
    public function testCanonicalMismatchTailorsTheIndexingFindingAndItsPlaybook(): void
    {
        $url = 'https://shop.test/red?color=1';
        $row = ['url' => $url, 'verdict' => 'NEUTRAL', 'coverage' => 'Duplicate, Google chose different canonical than user', 'date' => '2026-10-06', 'lost' => false, 'former_clicks' => null,
            'google_canonical' => 'https://shop.test/red', 'user_canonical' => $url, 'canonical_mismatch' => true, 'rich_errors' => []];
        $signals = new ProjectSignals(pages: [new DimensionRow($url, new Metrics(3, 90, 6))], panel: new PanelSnapshot(inspections: [$row]));
        $found = (new IndexingRule())->evaluate($signals);

        self::assertCount(1, $found);
        self::assertSame('indexing:' . $url, $found[0]->subject, 'Same subject as before, so existing findings update in place.');
        self::assertStringStartsWith('Google indexes another URL instead of ', $found[0]->title);
        self::assertContains(['label' => 'Google-selected canonical', 'value' => 'https://shop.test/red'], $found[0]->evidence);
        $plan = (new PlaybookLibrary())->for($found[0]->type, $found[0]->subject, $found[0]->evidence);
        self::assertSame('indexing:canonical', $plan->key);
        self::assertStringContainsString('https://shop.test/red', $plan->goal);
    }

    public function testRichResultErrorsBecomeTheirOwnFindingOnlyForRelevantPages(): void
    {
        $url = 'https://shop.test/shoe';
        $row = ['url' => $url, 'verdict' => 'PASS', 'coverage' => 'Submitted and indexed', 'date' => '2026-10-06', 'lost' => false, 'former_clicks' => null, 'rich_errors' => ['Product snippets: Missing field "offers"']];
        $rule = new IndexingRule();
        self::assertSame([], $rule->evaluate(new ProjectSignals(panel: new PanelSnapshot(inspections: [$row]))), 'A page without impressions or audit record is not relevant.');

        $found = $rule->evaluate(new ProjectSignals(pages: [new DimensionRow($url, new Metrics(120, 3000, 4))], panel: new PanelSnapshot(inspections: [$row])));
        self::assertCount(1, $found, 'A PASS page gets the rich-result finding only.');
        self::assertSame('indexing:rich:' . $url, $found[0]->subject);
        self::assertContains(['label' => 'Structured data errors', 'value' => 'Product snippets: Missing field "offers"'], $found[0]->evidence);
        self::assertSame(['indexing', 'inspection:' . hash('sha256', $url)], $rule->requires($found[0]));
        self::assertSame('indexing:rich', PlaybookLibrary::key(OpportunityType::Indexing, $found[0]->subject));
    }

    public function testSitemapsWithErrorsOrWarningsAreReported(): void
    {
        $signals = new ProjectSignals(sitemaps: [
            new Sitemap('https://shop.test/sitemap.xml', false, false, null, '2026-10-06 04:00:00', 0, 0, 120),
            new Sitemap('https://shop.test/old.xml', false, false, null, '2026-10-06 04:00:00', 2, 1, 40),
            new Sitemap('https://shop.test/news.xml', false, false, null, null, 0, 3, 10),
        ]);
        $found = (new SitemapRule())->evaluate($signals);

        self::assertSame(['indexing:sitemap:https://shop.test/old.xml', 'indexing:sitemap:https://shop.test/news.xml'], array_map(static fn($o): string => $o->subject, $found));
        self::assertStringStartsWith('Fix errors in sitemap', $found[0]->title);
        self::assertStringStartsWith('Clean up warnings in sitemap', $found[1]->title);
        self::assertGreaterThan($found[1]->impact, $found[0]->impact, 'Errors outrank warnings.');
        self::assertSame(['sitemaps'], SignalRequirements::for('indexing', $found[0]->subject));
        self::assertSame('indexing:sitemap', (new PlaybookLibrary())->for($found[0]->type, $found[0]->subject, $found[0]->evidence)->key);
    }

    public function testBingIndexDropAndCrawlErrorsUseTheirThresholds(): void
    {
        $day = static fn(string $date, int $inIndex, int $crawled = 100, int $errors = 0): array => ['date' => $date, 'inIndex' => $inIndex, 'crawled' => $crawled, 'errors' => $errors, 'code4xx' => $errors, 'code5xx' => 0, 'blocked' => 0];
        $rule = new BingCrawlRule();

        $found = $rule->evaluate(new ProjectSignals(bingCrawl: [$day('2026-09-01', 300), $day('2026-09-08', 298), $day('2026-10-01', 260, 100, 15), $day('2026-10-06', 250, 100, 10)]));
        self::assertSame(['indexing:bing:index', 'indexing:bing:errors'], array_map(static fn($o): string => $o->subject, $found));
        self::assertStringContainsString('298 on 2026-09-08', $found[0]->evidence[0]['value'], 'Compared with the last day on or before the window start.');
        self::assertSame('Bing dropped 48 of your pages from its index', $found[0]->title);
        self::assertSame(['bing_crawl'], $rule->requires($found[0]));

        self::assertSame([], $rule->evaluate(new ProjectSignals(bingCrawl: [$day('2026-09-01', 300), $day('2026-10-06', 285, 100, 4)])), 'A 5 % drop and 4 % errors stay below the thresholds.');
        self::assertSame([], $rule->evaluate(new ProjectSignals(bingCrawl: [$day('2026-09-01', 100), $day('2026-10-06', 85)])), 'Fewer than 20 pages is not reported.');
        self::assertSame([], $rule->evaluate(new ProjectSignals()));
    }

    public function testSurfaceDeclinesUseThePageThresholdsAndTheirOwnSources(): void
    {
        $signals = new ProjectSignals(searchConsoleWindow: '2026-09-09 – 2026-10-06', surfaces: [
            'discover' => ['now' => new Metrics(10, 4000, 0), 'previous' => new Metrics(40, 9000, 0)],
            'image' => ['now' => new Metrics(25, 9000, 9), 'previous' => new Metrics(30, 9000, 8)],
            'video' => ['now' => new Metrics(0, 10, 3), 'previous' => new Metrics(10, 50, 3)],
        ]);
        $found = (new SurfaceDeclineRule())->evaluate($signals);

        self::assertCount(1, $found, 'Image fell less than 30 %; video had fewer than 20 clicks before.');
        self::assertSame('surface:discover', $found[0]->subject);
        self::assertSame(OpportunityType::TrafficDecline, $found[0]->type);
        self::assertSame('Discover clicks fell by 75.0%', str_replace(' %', '%', $found[0]->title));
        self::assertNotContains('Average position', array_column($found[0]->evidence, 'label'), 'Discover has no position.');
        self::assertSame(['search_console_surfaces'], SignalRequirements::for('traffic_decline', $found[0]->subject));
        self::assertTrue(SignalRequirements::sampleComplete('traffic_decline', $found[0]->subject, ['google_page_sample' => ['status' => 'missing', 'date' => null]]), 'Not gated on the page sample.');
        self::assertSame('traffic_decline:surface', (new PlaybookLibrary())->for($found[0]->type, $found[0]->subject, $found[0]->evidence)->key);
    }

    public function testAnalyzerReportsTheExtrasFromStoredData(): void
    {
        $s = new Scenario(clock: new FrozenClock(new \DateTimeImmutable('2026-10-08')));
        $project = $s->acmeProject->id;
        $s->container->make(InMemoryRankings::class)->projects[$project] = ['workspace_id' => $s->acme->id, 'domain' => 'shop.acme.test'];
        $search = $s->container->make(InMemorySearchPerformance::class);
        $search->upsertDailyTotals($project, [new DailyMetrics('2026-10-06', new Metrics(50, 2000, 5))]);
        $search->replaceDay($project, '2026-10-06', ['query' => [new DimensionRow('shoes', new Metrics(50, 2000, 5))]]);
        $extras = $s->container->make(InMemorySearchConsoleExtras::class);
        $extras->upsertSearchTypeDays($project, SearchType::Discover, [new DailyMetrics('2026-09-01', new Metrics(40, 5000, 0)), new DailyMetrics('2026-10-01', new Metrics(10, 3000, 0))]);
        $extras->refreshedAt = '2026-10-07 06:00:00';
        $extras->replaceSitemaps($project, [new Sitemap('https://shop.acme.test/sitemap.xml', false, false, null, '2026-10-07 04:00:00', 2, 0, 50)]);
        $bing = $s->container->make(InMemoryBing::class);
        $bing->saveLink($project, $s->acme->id, 'https://shop.acme.test/', $s->owner->id);
        $bing->replaceCrawlData($project, [
            new BingCrawlDay('2026-09-01', 100, 0, 300, 50, 100, 0, 0, 0, 0, 0),
            new BingCrawlDay('2026-10-06', 100, 0, 250, 50, 100, 0, 0, 0, 0, 0),
        ], []);

        $s->container->make(OpportunityAnalyzer::class)->analyze($project, $s->acme->id);
        $repo = $s->container->make(InMemoryOpportunities::class);
        $subjects = [];
        foreach ($repo->list($project, 'open', null, 50, 0) as $row) {
            $subjects[$row->subject] = array_values(array_filter($row->evidence, static fn(array $e): bool => $e['label'] === 'Required sources'))[0]['value'] ?? '';
        }

        self::assertArrayHasKey('surface:discover', $subjects);
        self::assertStringContainsString('search_console_surfaces', $subjects['surface:discover']);
        self::assertArrayHasKey('indexing:sitemap:https://shop.acme.test/sitemap.xml', $subjects);
        self::assertStringContainsString('sitemaps', $subjects['indexing:sitemap:https://shop.acme.test/sitemap.xml']);
        self::assertArrayHasKey('indexing:bing:index', $subjects);
        $coverage = $repo->coverage($project);
        self::assertSame('available', $coverage['search_console_surfaces']['status']);
        self::assertSame('available', $coverage['sitemaps']['status']);
        self::assertSame('available', $coverage['bing_crawl']['status']);

        // Stale sitemap data withholds the finding instead of resolving it.
        $extras->sitemapsRefreshedAt[$project] = '2026-09-01 00:00:00';
        $result = $s->container->make(OpportunityAnalyzer::class)->analyze($project, $s->acme->id);
        self::assertNotNull($result);
        self::assertSame('stale', $repo->coverage($project)['sitemaps']['status']);
        self::assertContains('indexing:sitemap:https://shop.acme.test/sitemap.xml', array_map(static fn($r): string => $r->subject, $repo->list($project, 'open', null, 50, 0)));
    }
}
