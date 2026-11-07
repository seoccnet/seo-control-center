<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\View\LineChart;

final class LineChartTest extends TestCase
{
    public function testAnnotationDatesInterpolateAcrossSparseChecksWithoutMovingMeasurements(): void
    {
        $series = [['2026-09-01', 8.0], ['2026-09-11', 4.0], ['2026-09-12', 2.0]];
        $chart = LineChart::build($series);
        self::assertSame($chart->points[0]['x'], LineChart::xForDate($series, '2026-09-01'));
        self::assertSame($chart->points[1]['x'], LineChart::xForDate($series, '2026-09-11'));
        self::assertSame(round(($chart->points[0]['x'] + $chart->points[1]['x']) / 2, 1), LineChart::xForDate($series, '2026-09-06'));
        self::assertNull(LineChart::xForDate($series, '2026-09-13'));
        self::assertNull(LineChart::xForDate([], '2026-09-01'));
        self::assertSame(380.0, LineChart::xForDate([['2026-09-01', 1.0]], '2026-09-01'));
    }
    public function testInvertedChartPutsPositionOneAtTheTop(): void
    {
        $chart = LineChart::build([['2026-09-01', 1.0], ['2026-09-02', 10.0], ['2026-09-03', 4.0]], invert: true);

        self::assertSame((float) LineChart::TOP, $chart->points[0]['y'], 'Position 1 at the top.');
        self::assertSame((float) (LineChart::HEIGHT - LineChart::BOTTOM), $chart->points[1]['y'], 'Worst position at the baseline.');
        self::assertSame([1.0, 5.0, 10.0], array_column($chart->ticks, 'value'));
        self::assertSame('', $chart->areaPath, 'No area fill below a position line.');
    }

    public function testInvertedAxisSpansAtLeastTenPositionsAndGrowsWithData(): void
    {
        $small = LineChart::build([['2026-09-01', 2.0], ['2026-09-02', 3.0]], invert: true);
        self::assertSame(10.0, $small->ticks[count($small->ticks) - 1]['value']);

        $large = LineChart::build([['2026-09-01', 3.0], ['2026-09-02', 100.0]], invert: true);
        self::assertSame(1.0, $large->ticks[0]['value']);
        self::assertSame(100.0, $large->ticks[count($large->ticks) - 1]['value']);
    }

    public function testNormalChartIsUnchanged(): void
    {
        $chart = LineChart::build([['2026-09-01', 0.0], ['2026-09-02', 40.0]]);

        self::assertSame((float) (LineChart::HEIGHT - LineChart::BOTTOM), $chart->points[0]['y']);
        self::assertSame((float) LineChart::TOP, $chart->points[1]['y']);
        self::assertSame(0.0, $chart->ticks[0]['value']);
        self::assertNotSame('', $chart->areaPath);
    }
}
