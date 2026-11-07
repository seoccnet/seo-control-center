<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\PriorityBand;

final class PriorityBandTest extends TestCase
{
    public function testBandsAreFixedScoreRanges(): void
    {
        self::assertSame(PriorityBand::VeryLow, PriorityBand::of(0.0));
        self::assertSame(PriorityBand::VeryLow, PriorityBand::of(7.99));
        self::assertSame(PriorityBand::Low, PriorityBand::of(8.0));
        self::assertSame(PriorityBand::Medium, PriorityBand::of(18.0));
        self::assertSame(PriorityBand::High, PriorityBand::of(44.99));
        self::assertSame(PriorityBand::VeryHigh, PriorityBand::of(45.0));
        self::assertSame(PriorityBand::VeryHigh, PriorityBand::of(100.0));
        self::assertSame('priority-band-5', PriorityBand::VeryHigh->cssClass());
        self::assertSame('45+', PriorityBand::VeryHigh->range());
        self::assertSame('8–17', PriorityBand::Low->range());
        self::assertSame('0–7', PriorityBand::VeryLow->range());
    }

    public function testDocumentedExamplesLandInTheExpectedBands(): void
    {
        // About 1,000 extra clicks a month, good confidence, low effort.
        self::assertSame(PriorityBand::VeryHigh, PriorityBand::of(Opportunity::priorityOf(Opportunity::impactFromClicks(1000), 80, 1)));
        // About 100 clicks: high at low effort, lower once the effort rises.
        self::assertSame(PriorityBand::High, PriorityBand::of(Opportunity::priorityOf(Opportunity::impactFromClicks(100), 70, 1)));
        self::assertSame(PriorityBand::Low, PriorityBand::of(Opportunity::priorityOf(Opportunity::impactFromClicks(100), 70, 2)));
        self::assertSame(PriorityBand::Medium, PriorityBand::ofImpact(Opportunity::impactFromClicks(100)));
        self::assertSame(PriorityBand::VeryHigh, PriorityBand::ofImpact(Opportunity::impactFromClicks(1000)));
        self::assertSame(PriorityBand::VeryLow, PriorityBand::ofImpact(Opportunity::impactFromClicks(5)));
    }
}
