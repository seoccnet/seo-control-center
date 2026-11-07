<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Forecasting;

/** User-supplied calendar occurrence with when SEOcc first knew its date. */
final readonly class ForecastEvent
{
    public function __construct(public string $name, public string $date, public string $knownOn, public ?string $excludedOn = null) {}
}
