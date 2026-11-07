<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SearchConsole;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\View\LineChart;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\Shared\DateRange;

final class PerformanceValuesTest extends TestCase
{
    public function testDateRangeArithmetic(): void
    {
        $range = DateRange::endingOn('2026-03-01', 28);
        self::assertSame('2026-02-02', $range->fromString(), 'Crosses the month end correctly.');
        self::assertSame(28, $range->days());
        self::assertSame('2026-01-05', $range->previous()->fromString());
        self::assertSame('2026-02-01', $range->previous()->toString());
        self::assertCount(28, $range->eachDay());
        self::assertTrue($range->contains('2026-02-15'));
        self::assertFalse($range->contains('2026-03-02'));
    }

    public function testDateRangeRejectsInvalidInput(): void
    {
        foreach ([['2026-02-30', '2026-03-01'], ['2026-03-02', '2026-03-01'], ['26-3-1', '2026-03-01'], ["2026-03-01\n", '2026-03-02']] as [$from, $to]) {
            try {
                new DateRange($from, $to);
                self::fail(sprintf('Accepted %s..%s', $from, $to));
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        DateRange::endingOn('2026-03-01', 0);
    }

    public function testMetricsAggregateWithImpressionWeightedPosition(): void
    {
        $sum = Metrics::sum([new Metrics(10, 100, 2.0), new Metrics(0, 300, 10.0), Metrics::zero()]);

        self::assertSame(10, $sum->clicks);
        self::assertSame(400, $sum->impressions);
        self::assertEqualsWithDelta(8.0, $sum->position, 0.0001, '(2*100 + 10*300) / 400');
        self::assertEqualsWithDelta(0.025, $sum->ctr(), 0.0001);
        self::assertSame(0.0, Metrics::zero()->ctr(), 'No division by zero.');
        $this->expectException(\InvalidArgumentException::class);
        new Metrics(-1, 0, 0.0);
    }

    public function testChartScaleUsesRoundTicks(): void
    {
        self::assertSame([1.0, 1.0], LineChart::niceScale(0.0));
        self::assertSame([40.0, 10.0], LineChart::niceScale(36.0));
        self::assertSame([1500.0, 500.0], LineChart::niceScale(1234.0));
        self::assertSame('12.5K', LineChart::compact(12500));
        self::assertSame('1,500', LineChart::compact(1500));
        self::assertSame('2M', LineChart::compact(2_000_000));
    }

    public function testChartGeometryStaysInsideThePlot(): void
    {
        $chart = LineChart::build([['2026-09-01', 0.0], ['2026-09-02', 50.0], ['2026-09-03', 100.0]]);

        self::assertCount(3, $chart->points);
        self::assertSame((float) LineChart::LEFT, $chart->points[0]['x']);
        self::assertSame((float) (LineChart::WIDTH - LineChart::RIGHT), $chart->points[2]['x']);
        self::assertSame((float) LineChart::TOP, $chart->points[2]['y'], 'The maximum touches the top tick.');
        self::assertSame('1 Sep 2026', $chart->xLabels[0]['label']);
        self::assertStringStartsWith('M', $chart->linePath);
        self::assertStringEndsWith('Z', $chart->areaPath);
        self::assertSame('', LineChart::build([])->linePath);
    }
}
