<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Analytics;

final readonly class Ga4Report
{
    /** @param list<LandingPageMetrics> $rows */
    public function __construct(public array $rows, public string $currency, public string $timezone, public bool $limited, public bool $thresholded) {}
}
