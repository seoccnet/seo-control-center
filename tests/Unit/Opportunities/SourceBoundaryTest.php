<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Modules\Bing\BingWeeklyRow;
use SeoControlCenter\Modules\Opportunities\OpportunityAnalyzer;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBing;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOpportunities;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

final class SourceBoundaryTest extends TestCase
{
    public function testStaleBingCannotResolveOrRewriteFreshGoogleOnlyFinding(): void
    {
        $s = $this->scenario();
        $project = $s->acmeProject->id;
        $search = $s->container->make(InMemorySearchPerformance::class);
        $search->upsertDailyTotals($project, [new DailyMetrics('2026-10-06', new Metrics(0, 1000, 2))]);
        $search->replaceDay($project, '2026-10-06', ['query' => [new DimensionRow('topic', new Metrics(0, 1000, 2))]]);
        $analyzer = $s->container->make(OpportunityAnalyzer::class);
        $analyzer->analyze($project, $s->acme->id);
        $repo = $s->container->make(InMemoryOpportunities::class);
        $before = $repo->list($project, 'open', null, 10, 0);
        self::assertCount(1, $before);
        $bing = $s->container->make(InMemoryBing::class);
        $bing->saveLink($project, $s->acme->id, 'https://shop.acme.test', $s->owner->id);
        $bing->days[$project] = [new DailyMetrics('2026-09-20', new Metrics(900, 1000, 2))];
        $bing->rows[$project] = [new BingWeeklyRow('2026-09-20', PerformanceDimension::Query, new DimensionRow('topic', new Metrics(900, 1000, 2)))];
        $result = $analyzer->analyze($project, $s->acme->id);
        self::assertNotNull($result);
        self::assertSame(0, $result['resolved']);
        $after = $repo->list($project, 'open', null, 10, 0);
        self::assertCount(1, $after);
        self::assertSame($before[0]->publicId, $after[0]->publicId);
        self::assertSame($before[0]->impact, $after[0]->impact);
        self::assertSame('stale', $repo->coverage($project)['bing']['status']);
    }

    public function testFreshBingCanStandAloneWhenGoogleIsStale(): void
    {
        $s = $this->scenario();
        $project = $s->acmeProject->id;
        $search = $s->container->make(InMemorySearchPerformance::class);
        $search->upsertDailyTotals($project, [new DailyMetrics('2026-09-20', new Metrics(100, 1000, 2))]);
        $search->replaceDay($project, '2026-09-20', ['query' => [new DimensionRow('old topic', new Metrics(100, 1000, 2))]]);
        $bing = $s->container->make(InMemoryBing::class);
        $bing->saveLink($project, $s->acme->id, 'https://shop.acme.test', $s->owner->id);
        $bing->days[$project] = [new DailyMetrics('2026-10-06', new Metrics(0, 1000, 2))];
        $bing->rows[$project] = [new BingWeeklyRow('2026-10-06', PerformanceDimension::Query, new DimensionRow('new topic', new Metrics(0, 1000, 2)))];
        $s->container->make(OpportunityAnalyzer::class)->analyze($project, $s->acme->id);
        $repo = $s->container->make(InMemoryOpportunities::class);
        $found = $repo->list($project, 'open', null, 10, 0);
        self::assertCount(1, $found);
        self::assertStringContainsString('new topic', $found[0]->title);
        self::assertContains(['label' => 'Required sources', 'value' => 'bing'], $found[0]->evidence);
    }

    public function testPendingDimensionsDoNotResolveExistingGoogleFindings(): void
    {
        $s = $this->scenario();
        $project = $s->acmeProject->id;
        $search = $s->container->make(InMemorySearchPerformance::class);
        $search->upsertDailyTotals($project, [new DailyMetrics('2026-10-06', new Metrics(0, 1000, 2))]);
        $search->replaceDay($project, '2026-10-06', ['query' => [new DimensionRow('topic', new Metrics(0, 1000, 2))]]);
        $analyzer = $s->container->make(OpportunityAnalyzer::class);
        $analyzer->analyze($project, $s->acme->id);
        $search->upsertDailyTotals($project, [new DailyMetrics('2026-10-07', new Metrics(100, 1000, 2))]);
        $result = $analyzer->analyze($project, $s->acme->id);
        self::assertNotNull($result);
        self::assertSame(0, $result['resolved']);
        $repo = $s->container->make(InMemoryOpportunities::class);
        self::assertSame(1, $repo->count($project, 'open'));
        self::assertSame('missing', $repo->coverage($project)['search_console']['status']);
    }

    public function testTrafficComparisonUsesGoogleOnlyPages(): void
    {
        $url = 'https://shop.acme.test/topic';
        $signals = new \SeoControlCenter\Domain\Opportunities\ProjectSignals(
            pages: [new DimensionRow($url, new Metrics(100, 2000, 5))],
            previousPages: [$url => new Metrics(100, 2000, 5)],
            googlePages: [new DimensionRow($url, new Metrics(30, 1000, 5))],
        );
        $found = (new \SeoControlCenter\Domain\Opportunities\Rules\TrafficDeclineRule())->evaluate($signals);
        self::assertCount(1, $found);
        self::assertStringContainsString('70.0', $found[0]->title);
    }

    public function testMissingResearchCannotReplaceKnownDifficultyWithCheaperEffort(): void
    {
        $s = $this->scenario();
        $project = $s->acmeProject->id;
        $search = $s->container->make(InMemorySearchPerformance::class);
        $search->upsertDailyTotals($project, [new DailyMetrics('2026-10-06', new Metrics(50, 1000, 8))]);
        $search->replaceDay($project, '2026-10-06', ['query' => [new DimensionRow('topic', new Metrics(50, 1000, 8))]]);
        $research = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryResearch::class);
        $research->queue($project, $s->acme->id, 'ranked', '', [], null);
        $research->complete($project, 'ranked', '', [(new \SeoControlCenter\Domain\Research\ResearchKeyword('topic', 3000, null, 80, 'commercial', 8))->toArray()]);
        $analyzer = $s->container->make(OpportunityAnalyzer::class);
        $analyzer->analyze($project, $s->acme->id);
        $repo = $s->container->make(InMemoryOpportunities::class);
        $before = $repo->list($project, 'open', null, 10, 0);
        self::assertCount(1, $before);
        self::assertSame(3, $before[0]->effort);
        $research->queue($project, $s->acme->id, 'ranked', '', [], null);
        $analyzer->analyze($project, $s->acme->id);
        $after = $repo->list($project, 'open', null, 10, 0);
        self::assertCount(1, $after);
        self::assertSame($before[0]->effort, $after[0]->effort);
        self::assertSame($before[0]->priority, $after[0]->priority);
    }

    public function testGa4DisconnectPreservesPreviouslyValuedScoreAndDoesNotLeakForeignValues(): void
    {
        $s = $this->scenario();
        $project = $s->acmeProject->id;
        $search = $s->container->make(InMemorySearchPerformance::class);
        $search->upsertDailyTotals($project, [new DailyMetrics('2026-10-06', new Metrics(50, 1000, 8))]);
        $search->replaceDay($project, '2026-10-06', ['query' => [new DimensionRow('buy shoes', new Metrics(50, 1000, 8))]]);
        $pages = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryPages::class);
        $pages->spans[$project] = ['first' => '2026-09-09', 'last' => '2026-10-06'];
        $pages->queryRows[$project] = [new \SeoControlCenter\Modules\Pages\PageQuery('buy shoes', 'https://shop.acme.test/shop', new Metrics(50, 1000, 8))];
        $ga4 = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryGa4::class);
        $ga4->saveCredentials($s->acme->id, new \SeoControlCenter\Domain\Analytics\Ga4Credentials('test', 'test'));
        $ga4->link($project, '123');
        $ga4->saveReport($project, new \SeoControlCenter\Domain\Shared\DateRange('2026-09-09', '2026-10-06'), new \SeoControlCenter\Domain\Analytics\Ga4Report([new \SeoControlCenter\Domain\Analytics\LandingPageMetrics('2026-10-06', 'https://shop.acme.test/shop', 100, 10.0, 300.0), new \SeoControlCenter\Domain\Analytics\LandingPageMetrics('2026-10-06', 'https://shop.acme.test/other', 900, 0.0, 700.0)], 'EUR', 'UTC', false, false), '123');
        $analyzer = $s->container->make(OpportunityAnalyzer::class);
        $analyzer->analyze($project, $s->acme->id);
        $repo = $s->container->make(InMemoryOpportunities::class);
        $before = $repo->list($project, 'open', null, 10, 0);
        self::assertCount(1, $before);
        self::assertContains('Est. extra key events', array_column($before[0]->evidence, 'label'));
        self::assertStringContainsString('ga4', array_column($before[0]->evidence, 'value', 'label')['Required sources']);
        self::assertSame([], $repo->list($s->rivalProject->id, 'open', null, 10, 0));
        $ga4->deleteCredentials($s->acme->id);
        $analyzer->analyze($project, $s->acme->id);
        $after = $repo->list($project, 'open', null, 10, 0);
        self::assertCount(1, $after);
        self::assertSame($before[0]->priority, $after[0]->priority);
        self::assertSame($before[0]->impact, $after[0]->impact);
        self::assertSame('missing', $repo->coverage($project)['ga4']['status']);
    }

    public function testTruncatedSamplePreservesFindingOutsideCapAndReportsActualCount(): void
    {
        $s = $this->scenario();
        $project = $s->acmeProject->id;
        $search = $s->container->make(InMemorySearchPerformance::class);
        $search->upsertDailyTotals($project, [new DailyMetrics('2026-10-06', new Metrics(0, 100, 2))]);
        $search->replaceDay($project, '2026-10-06', ['query' => [new DimensionRow('outside', new Metrics(0, 1000, 2))]]);
        $analyzer = $s->container->make(OpportunityAnalyzer::class);
        $analyzer->analyze($project, $s->acme->id);
        $repo = $s->container->make(InMemoryOpportunities::class);
        $id = $repo->list($project, 'open', null, 1, 0)[0]->publicId;
        $rows = [new DimensionRow('outside', new Metrics(0, 1000, 2))];
        for ($i = 0; $i < 500; ++$i) {
            $rows[] = new DimensionRow('large ' . $i, new Metrics(200, 10000, 1));
        }
        $search->replaceDay($project, '2026-10-06', ['query' => $rows]);
        $analyzer->analyze($project, $s->acme->id);
        self::assertSame('open', $repo->find($project, $id)?->status);
        self::assertSame('missing', $repo->coverage($project)['query_sample']['status']);
        self::assertStringContainsString('top 500 of 501', $repo->coverage($project)['query_sample']['notice'] ?? '');
        $search->replaceDay($project, '2026-10-06', ['query' => [new DimensionRow('outside', new Metrics(900, 1000, 2))]]);
        $analyzer->analyze($project, $s->acme->id);
        self::assertSame('resolved', $repo->find($project, $id)?->status);
    }

    public function testCtrCalibrationUsesNonGenericProjectNameAsBrandEvidence(): void
    {
        $s = $this->scenario();
        $project = $s->acmeProject->id;
        $rows = [new DimensionRow('Special Brand tools', new Metrics(800, 1000, 8)), new DimensionRow('garden tools', new Metrics(200, 1000, 3))];
        $search = $s->container->make(InMemorySearchPerformance::class);
        $search->upsertDailyTotals($project, [new DailyMetrics('2026-10-06', new Metrics(1000, 2000, 5))]);
        $search->replaceDay($project, '2026-10-06', ['query' => $rows]);
        $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryTrends::class)->snapshots[$project] = new \SeoControlCenter\Domain\Opportunities\TrendSnapshot(queries: $rows, queryCount: 2, projectName: 'Special Brand');
        $signals = $s->container->make(\SeoControlCenter\Modules\Opportunities\SignalCollector::class)->collect($project, $s->acme->id);
        self::assertNotNull($signals->ctrModel);
        self::assertSame(.03, $signals->ctrModel->expected(8));
        self::assertEqualsWithDelta(.155, $signals->ctrModel->expected(3), .000001);
    }

    private function scenario(): Scenario
    {
        $s = new Scenario(clock: new FrozenClock(new \DateTimeImmutable('2026-10-08')));
        $s->container->make(InMemoryRankings::class)->projects[$s->acmeProject->id] = ['workspace_id' => $s->acme->id, 'domain' => 'shop.acme.test'];
        return $s;
    }
}
