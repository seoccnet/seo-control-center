<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\CtrModel;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\LowCtrRule;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;

final class CtrModelTest extends TestCase
{
    public function testWeightedCalibrationBrandExclusionAndFallback(): void
    {
        $model = CtrModel::train([
            new DimensionRow('garden tools', new Metrics(200, 1000, 3)),
            new DimensionRow('Acme garden tools', new Metrics(900, 1000, 3)),
            new DimensionRow('known brand', new Metrics(800, 1000, 2)),
            new DimensionRow('', new Metrics(100000, 1000000, 3)),
        ], 'acme.test');
        self::assertEqualsWithDelta(.155, $model->expected(3), .0000001);
        self::assertSame(.15, $model->expected(2));
        self::assertSame(.08, $model->expected(4));
        self::assertStringContainsString('1,000 non-brand impressions', $model->basis(3));
        self::assertStringContainsString('global fallback', $model->basis(4));
        self::assertSame(0.0, $model->expected(null));
        $suffix = CtrModel::train([new DimensionRow('buy online', new Metrics(200, 1000, 3))], 'acme.online');
        self::assertEqualsWithDelta(.155, $suffix->expected(3), .0000001, 'A public suffix must not exclude a non-brand query.');
    }

    public function testWilsonRejectsInsufficientEvidenceAndUsesSiteCurve(): void
    {
        self::assertEqualsWithDelta(.018846005918320894, CtrModel::upper(0, 200), .000001);
        self::assertFalse(LowCtrRule::applies(new DimensionRow('topic', new Metrics(1, 200, 10))));
        $row = new DimensionRow('topic', new Metrics(10, 1000, 3));
        self::assertTrue(LowCtrRule::applies($row));
        $model = CtrModel::train([new DimensionRow('other', new Metrics(100, 10000, 3))], 'acme.test');
        self::assertFalse(LowCtrRule::applies($row, new ProjectSignals(ctrModel: $model)));
    }

    public function testAiOverviewIsAPossibleExplanation(): void
    {
        $row = new DimensionRow('topic', new Metrics(0, 2000, 3));
        $found = (new LowCtrRule())->evaluate(new ProjectSignals(queries: [$row], aiOverviews: [['keyword' => 'topic', 'position' => 3, 'cited' => false, 'domains' => []]]));
        self::assertCount(1, $found);
        self::assertStringContainsString('AI Overview', $found[0]->title);
        self::assertStringContainsString('not a proven cause', $found[0]->explanation);
    }
}
