<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Opportunities\OutcomeMeasurementReader;
use SeoControlCenter\Domain\Opportunities\OutcomeMetrics;
use SeoControlCenter\Domain\Shared\DateRange;

final class InMemoryOutcomeMeasurements implements OutcomeMeasurementReader
{
    /** @var array<int, array<string, array<string, OutcomeMetrics>>> */
    public array $measurements = [];
    public function measure(int $projectId, string $subject, DateRange $range): ?OutcomeMetrics
    {
        return $this->measurements[$projectId][$subject][$range->fromString() . ':' . $range->toString()] ?? null;
    }
}
