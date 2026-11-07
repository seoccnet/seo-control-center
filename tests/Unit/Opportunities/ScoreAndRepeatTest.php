<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ScoreBreakdown;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Modules\Research\RepeatedLookup;
use SeoControlCenter\Modules\Research\ResearchResult;

/**
 * ADR-038, D3: one score breakdown from stored evidence, and the rule
 * that refuses a repeated paid lookup unless the user confirms.
 */
final class ScoreAndRepeatTest extends TestCase
{
    public function testBreakdownReadsTheStoredBasisOfEveryFactor(): void
    {
        $o = new StoredOpportunity('0192f0a0-0000-7000-8000-000000000301', OpportunityType::QuickWin, 'Lift “red shoes”', 'Near the top.', [
            ['label' => 'Query', 'value' => 'red shoes'],
            ['label' => 'Impact basis', 'value' => '1,200 impressions at position 6'],
            ['label' => 'BID verdict', 'value' => 'Target: fits the offer'],
            ['label' => 'Business potential', 'value' => '3/3 sells shoes'],
            ['label' => 'Intent', 'value' => 'commercial'],
            ['label' => 'Impact calculation', 'value' => '120 clicks → 70'],
            ['label' => 'Confidence', 'value' => '62: 1,200 impressions; stable position'],
            ['label' => 'Effort calculation', 'value' => 'one title change'],
            ['label' => 'Zero-click risk', 'value' => 'Low: no AI Overview'],
            ['label' => 'Required sources', 'value' => 'google_queries, rankings'],
        ], 70, 62, 1, 43.4, 'open', '2026-10-01 00:00:00', '2026-10-16 00:00:00', 120.4);
        $b = ScoreBreakdown::of($o);
        self::assertSame('70 impact × 62 confidence / 100 / 1 effort = 43.40 priority', $b['formula']);
        $f = array_column($b['factors'], null, 'key');
        self::assertSame(['about 120 extra clicks a month', '1,200 impressions at position 6'], [$f['demand']['value'], $f['demand']['basis']]);
        self::assertSame('Target · business potential 3/3 · commercial', $f['relevance']['value']);
        self::assertSame(['62 of 100', '1,200 impressions; stable position'], [$f['confidence']['value'], $f['confidence']['basis']]);
        self::assertSame(['low', 'one title change'], [$f['effort']['value'], $f['effort']['basis']]);
        self::assertSame('low zero-click risk', $f['risk']['value']);
        self::assertSame('google_queries, rankings', $f['evidence']['value']);
        self::assertSame('priority 43.40 · impact 70 · confidence 62 · effort low · about 120 extra clicks a month', ScoreBreakdown::summary($o));

        $bare = new StoredOpportunity('0192f0a0-0000-7000-8000-000000000302', OpportunityType::SiteIssue, 'Fix', '', [], 20, 40, 3, 2.67, 'open', '2026-10-01 00:00:00', '2026-10-16 00:00:00');
        $f = array_column(ScoreBreakdown::of($bare)['factors'], null, 'key');
        self::assertSame(['not estimated', 'not scored', 'none recorded', 'high'], [$f['demand']['value'], $f['relevance']['value'], $f['risk']['value'], $f['effort']['value']]);
    }

    public function testRepeatedLookupsAreRefusedOnlyWhenSameAndFresh(): void
    {
        $now = new \DateTimeImmutable('2026-10-16 12:00:00');
        $params = ['location' => 2840, 'language' => 'en', 'domain' => 'shop.test', 'seeds' => ['Red Shoes', 'blue shoes'], '_request_id' => 'a'];
        $done = new ResearchResult(1, 'ideas', '', 'done', $params, [], null, '2026-10-01 10:00:00', '2026-10-01 10:05:00');
        try {
            RepeatedLookup::guard($done, ['location' => 2840, 'language' => 'en', 'domain' => 'shop.test', 'seeds' => ['blue shoes', 'red shoes']], $now, false, 'Expanding these seeds');
            self::fail('Same seeds in another order and case within 30 days must be refused.');
        } catch (RepeatedLookup $e) {
            self::assertSame('2026-10-01', $e->ranOn);
            self::assertSame(409, $e->statusCode());
            self::assertStringContainsString('Expanding these seeds already ran on 2026-10-01', $e->getMessage());
        }
        RepeatedLookup::guard($done, $params, $now, true, 'x');
        RepeatedLookup::guard($done, [...$params, 'seeds' => ['red shoes', 'green shoes']], $now, false, 'x');
        RepeatedLookup::guard($done, [...$params, 'location' => 2276], $now, false, 'x');
        RepeatedLookup::guard($done, $params, $now->modify('+31 days'), false, 'x');
        RepeatedLookup::guard(new ResearchResult(1, 'ideas', '', 'failed', $params, [], 'x', '2026-10-01 10:00:00', null), $params, $now, false, 'x');
        RepeatedLookup::guard(null, $params, $now, false, 'x');
        self::assertSame(RepeatedLookup::inputs(['b' => 1, 'a' => ['Y', 'x'], '_batch' => [1], 'scheduled' => true]), ['a' => ['x', 'y'], 'b' => 1]);
    }
}
