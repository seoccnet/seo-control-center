<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing;

use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;

/**
 * One query or page in one of Bing's weekly buckets ($weekEnd is the bucket's date).
 */
final readonly class BingWeeklyRow
{
    public function __construct(
        public string $weekEnd,
        public PerformanceDimension $dimension,
        public DimensionRow $row,
    ) {}
}
