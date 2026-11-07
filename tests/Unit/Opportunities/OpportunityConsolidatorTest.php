<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\OpportunityConsolidator;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;

final class OpportunityConsolidatorTest extends TestCase
{
    public function testQueryVariantsMergeWithoutChangingPrimaryIdentityAndDoNotAddGains(): void
    {
        $a = $this->row('a', OpportunityType::QuickWin, 'Red  Shoes', 40, 100);
        $b = $this->row('b', OpportunityType::ContentGap, 'red shoes', 20, 300);
        $rows = (new OpportunityConsolidator())->consolidate([$b, $a], 'open');
        self::assertCount(1, $rows);
        self::assertSame('a', $rows[0]->publicId);
        self::assertSame(100.0, $rows[0]->estimatedClicks);
        self::assertSame('query:red shoes', OpportunityConsolidator::key($a));
        self::assertStringContainsString('opportunity b', $rows[0]->evidence[4]['value']);
        self::assertCount(1, (new OpportunityConsolidator())->consolidate([$a, $b], 'open', OpportunityType::ContentGap), 'Filters can find a supporting type without creating a duplicate primary.');
    }

    public function testClosedDecisionSuppressesDuplicateAcrossTypesAndRemainsFindable(): void
    {
        $open = $this->row('a', OpportunityType::QuickWin, 'red shoes', 40, 100);
        $done = $this->row('b', OpportunityType::ContentGap, 'RED SHOES', 20, 300, 'done');
        $model = new OpportunityConsolidator();
        self::assertSame([], $model->consolidate([$open, $done], 'open'));
        self::assertSame('b', $model->consolidate([$open, $done], 'done')[0]->publicId);
    }

    public function testPagePlanAvoidsAddingOverlappingPageAndQueryEstimates(): void
    {
        $a = $this->row('a', OpportunityType::QuickWin, 'red shoes', 40, 100);
        $b = $this->row('b', OpportunityType::ContentGap, 'blue shoes', 30, 50);
        $c = new StoredOpportunity('c', OpportunityType::LowCtr, 'Page CTR', 'Review', [['label' => 'Page', 'value' => 'https://shop.test/shoes/?campaign=a']], 50, 70, 2, 25, 'open', '2026-10-01', '2026-10-08', 200);
        $unknown = new StoredOpportunity('d', OpportunityType::Indexing, 'Indexing', 'Review', [['label' => 'Page', 'value' => 'https://shop.test/shoes']], 50, 70, 2, 20, 'open', '2026-10-01', '2026-10-08');
        $model = new OpportunityConsolidator();
        $plans = $model->pages($model->consolidate([$a, $b, $c, $unknown], 'open'));
        self::assertCount(1, $plans);
        self::assertSame('shop.test/shoes', $plans[0]['page']);
        self::assertSame(200.0, $plans[0]['estimated_monthly_clicks']);
        self::assertCount(3, $plans[0]['actions']);
    }

    private function row(string $id, OpportunityType $type, string $query, float $priority, float $estimate, string $status = 'open'): StoredOpportunity
    {
        return new StoredOpportunity($id, $type, 'Review ' . $query, 'Review intent', [['label' => 'Query', 'value' => $query], ['label' => 'Page', 'value' => 'https://shop.test/shoes']], 50, 80, 1, $priority, $status, '2026-10-01', '2026-10-08', $estimate);
    }
}
