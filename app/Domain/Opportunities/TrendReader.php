<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Shared\DateRange;

interface TrendReader
{
    /** Bounded top 500 subjects by current impressions; monthly evidence only when 13 consecutive marks exist. */
    public function snapshot(int $projectId, DateRange $range): TrendSnapshot;
}
