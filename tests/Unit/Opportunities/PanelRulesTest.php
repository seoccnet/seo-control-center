<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\ContextModel;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\PanelSnapshot;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\CannibalizationRule;
use SeoControlCenter\Domain\Opportunities\Rules\IndexingRule;
use SeoControlCenter\Domain\Opportunities\Rules\InternalLinksRule;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;

final class PanelRulesTest extends TestCase
{
    public function testIndividualStaleRecordsCannotBeHiddenByGlobalFreshness(): void
    {
        $coverage = ['rank_scope' => ['status' => 'available', 'date' => '2026-10-08'], 'rankings' => ['status' => 'available', 'date' => '2026-10-08'], 'rank_record:' . hash('sha256', 'old-keyword') => ['status' => 'stale', 'date' => '2026-08-01'], 'vitals_scope' => ['status' => 'available', 'date' => '2026-10-08']];
        self::assertFalse(\SeoControlCenter\Domain\Opportunities\SignalRequirements::sampleComplete('quick_win', 'keyword:old-keyword', $coverage));
        self::assertFalse(\SeoControlCenter\Domain\Opportunities\SignalRequirements::sampleComplete('site_issue', 'crux:https://shop.test/a', $coverage));
        self::assertSame(['rankings' => $coverage['rankings']], \SeoControlCenter\Domain\Opportunities\SignalCoverage::summary($coverage));
    }

    public function testTechnicalValueUsesAllAffectedExposureAndNeverCallsItGain(): void
    {
        $signals = new ProjectSignals(coverage: ['ga4' => ['status' => 'available', 'date' => '2026-10-06'], 'audit_traffic' => ['status' => 'available', 'date' => '2026-10-06']], ga4Totals: ['sessions' => 1000, 'conversions' => 10.0, 'revenue' => 100.0, 'pages' => 10], panel: new PanelSnapshot(issueTraffic: ['thin_content' => ['pages' => 20, 'clicks' => 280, 'sessions' => 1000, 'conversions' => 40.0, 'revenue' => 400.0]]));
        $opportunity = new Opportunity(OpportunityType::SiteIssue, 'site:thin_content', 'Review', 'Review', [], 40, 0, 2);
        $result = (new \SeoControlCenter\Domain\Opportunities\ValueModel($signals))->assess($opportunity);
        self::assertSame(Opportunity::impactFromClicks(1050), $result['impact'], 'Smoothed relative value = (400 + 20) / 1200 / .1 = 3.5; 280 × 30/28 × 3.5 = 1050.');
        self::assertSame(['ga4', 'audit_traffic'], $result['sources']);
        self::assertStringContainsString('No incremental gain predicted', $result['evidence'][1]['value']);
    }

    public function testCannibalizationRequiresMeaningfulSharesAndPositionFlip(): void
    {
        $rows = [
            ['query' => 'red shoes', 'url' => 'https://shop.test/a', 'clicks' => 30, 'impressions' => 600, 'before' => 4.0, 'after' => 8.0],
            ['query' => 'red shoes', 'url' => 'https://shop.test/b', 'clicks' => 20, 'impressions' => 400, 'before' => 8.0, 'after' => 4.0],
        ];
        $rule = new CannibalizationRule();
        $found = $rule->evaluate(new ProjectSignals(panel: new PanelSnapshot(splits: $rows)));
        self::assertCount(1, $found);
        self::assertSame('query:red shoes', $found[0]->subject);
        self::assertNull($found[0]->estimatedClicks, 'Traffic at stake is not an incremental gain estimate.');
        $rows[1]['after'] = 9.0;
        self::assertSame([], $rule->evaluate(new ProjectSignals(panel: new PanelSnapshot(splits: $rows))));
        $rows[1]['impressions'] = 10;
        $rows[1]['after'] = 3.0;
        self::assertSame([], $rule->evaluate(new ProjectSignals(panel: new PanelSnapshot(splits: $rows))));
    }

    public function testIndexingDistinguishesKnownLossFromUncheckedAndRecoveredPages(): void
    {
        $row = ['url' => 'https://shop.test/a', 'verdict' => 'FAIL', 'coverage' => 'Blocked by noindex', 'date' => '2026-10-06', 'lost' => true, 'former_clicks' => 100];
        $rule = new IndexingRule();
        $found = $rule->evaluate(new ProjectSignals(panel: new PanelSnapshot(inspections: [$row])));
        self::assertCount(1, $found);
        self::assertContains('inspection:' . hash('sha256', $row['url']), $rule->requires($found[0]));
        self::assertContains(['label' => 'Former Google clicks', 'value' => 'unknown'], $found[0]->evidence);
        $row['verdict'] = 'PASS';
        self::assertSame([], $rule->evaluate(new ProjectSignals(panel: new PanelSnapshot(inspections: [$row]))));
        self::assertSame([], $rule->evaluate(new ProjectSignals()));
    }

    public function testInternalLinksRequireAuditedIndexableDonorsWithoutExistingLink(): void
    {
        $target = 'https://shop.test/a';
        $donor = 'https://shop.test/b';
        $pages = [new DimensionRow($target, new Metrics(5, 200, 8)), new DimensionRow($donor, new Metrics(100, 1000, 3))];
        $audit = [['url' => $target, 'inbound' => 1, 'words' => 300, 'noindex' => false], ['url' => $donor, 'inbound' => 3, 'words' => 500, 'noindex' => false]];
        $rule = new InternalLinksRule();
        self::assertCount(1, $rule->evaluate(new ProjectSignals(pages: $pages, panel: new PanelSnapshot(auditPages: $audit))));
        self::assertSame([], $rule->evaluate(new ProjectSignals(pages: $pages, panel: new PanelSnapshot(auditPages: $audit, outgoing: [$donor => [$target]]))));
        $audit[1]['noindex'] = true;
        self::assertSame([], $rule->evaluate(new ProjectSignals(pages: $pages, panel: new PanelSnapshot(auditPages: $audit))));
    }

    public function testRelevantRecentChangesReduceConfidenceWithoutClaimingCause(): void
    {
        $url = 'https://shop.test/a';
        $opportunity = new Opportunity(OpportunityType::TrafficDecline, 'page:' . $url, 'Decline', 'Review', [['label' => 'Page', 'value' => $url]], 50, 0, 2);
        $panel = new PanelSnapshot(annotations: [['id' => 'annotation-id', 'date' => '2026-10-01', 'url' => $url, 'title' => 'Changed title']], pageChanges: [['id' => 'watch-id', 'date' => '2026-10-02', 'url' => $url, 'fields' => ['canonical', 'noindex']]], projectPublicId: 'project-id');
        $assessment = (new ContextModel(new ProjectSignals(panel: $panel)))->assess($opportunity);
        self::assertSame(-15, $assessment['adjustment'], 'Multiple overlapping changes apply one bounded penalty.');
        self::assertSame(['annotations', 'page_monitoring'], $assessment['sources']);
        self::assertStringContainsString('Correlation does not establish cause', $assessment['evidence'][0]['value']);
        self::assertStringContainsString('/annotations/annotation-id/impact', $assessment['evidence'][0]['value']);
        $other = new Opportunity(OpportunityType::TrafficDecline, 'page:other', 'Decline', 'Review', [['label' => 'Page', 'value' => 'other']], 50, 0, 2);
        self::assertSame(0, (new ContextModel(new ProjectSignals(panel: $panel)))->assess($other)['adjustment']);
    }
}
