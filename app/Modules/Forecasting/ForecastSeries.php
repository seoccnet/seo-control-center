<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Forecasting;

/** A bound, single-metric series. Missing dates remain missing. */
final readonly class ForecastSeries
{
    /** @param array<string, float> $values */
    public function __construct(public string $key, public string $label, public string $binding, public array $values, public ?string $blocked = null) {}
}
