<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Shared\DateRange;

interface OutcomeMeasurementReader
{
    /** Null means incomplete import coverage or an unobserved subject, never zero traffic. */
    public function measure(int $projectId, string $subject, DateRange $range): ?OutcomeMetrics;
}
