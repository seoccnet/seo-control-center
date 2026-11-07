<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Local;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalGrid;

final class LocalGridTest extends TestCase
{
    public function testPointsAreSpacedAroundTheCentre(): void
    {
        $grid = new LocalGrid(1, '2026-10-03', 3, 1000, new LocalArea(45.0, 18.0, 14), []);
        $areas = $grid->areas();

        self::assertCount(9, $areas);
        self::assertSame([45.0, 18.0], [$areas[4]->latitude, $areas[4]->longitude], 'the middle point is the centre');
        self::assertSame(14, $areas[0]->zoom);
        self::assertEqualsWithDelta(45.008983, $areas[0]->latitude, 0.000001, 'north-west: 1 km north');
        self::assertEqualsWithDelta(17.987297, $areas[0]->longitude, 0.000002, '1 km west at 45° N');
        self::assertEqualsWithDelta(44.991017, $areas[8]->latitude, 0.000001, 'south-east');
        self::assertGreaterThan(18.0, $areas[8]->longitude);
        self::assertSame($areas[1]->longitude, 18.0, 'the middle column keeps the longitude');
    }

    public function testPositionsRowsAndAverage(): void
    {
        $grid = new LocalGrid(1, '2026-10-03', 3, 500, new LocalArea(1.0, 1.0), [0 => 1, 1 => 4, 2 => null, 4 => 12]);

        self::assertSame([[1, 4, null], [false, 12, false], [false, false, false]], $grid->rows());
        self::assertSame(3, $grid->found());
        self::assertSame(9.5, $grid->averagePosition(), '(1 + 4 + 21 + 12) / 4');
        self::assertFalse($grid->isComplete());
        self::assertNull((new LocalGrid(1, '2026-10-03', 3, 500, new LocalArea(1.0, 1.0), []))->averagePosition());
    }
}
