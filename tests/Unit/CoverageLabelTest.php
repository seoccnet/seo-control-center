<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Opportunities\CoverageLabel;

final class CoverageLabelTest extends TestCase
{
    public function testTruncatedSearchSamplesReadPartialNotMissing(): void
    {
        self::assertSame('partial', CoverageLabel::status(['status' => 'missing', 'date' => '2026-10-06', 'notice' => 'Analysis covers top 500 of 2416 Google queries by impressions']));
        self::assertSame('missing', CoverageLabel::status(['status' => 'missing', 'date' => null, 'notice' => 'Google query sample unavailable; Bing total unknown']));
        self::assertSame('missing', CoverageLabel::status(['status' => 'missing', 'date' => null]));
        self::assertSame('available', CoverageLabel::status(['status' => 'available', 'date' => '2026-10-06', 'notice' => 'Analysis covers top 120 of 120 Google queries by impressions']));
        self::assertSame('stale', CoverageLabel::status(['status' => 'stale', 'date' => '2026-09-01']));
    }
}
