<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Opportunities\ConfidenceObservation;
use SeoControlCenter\Domain\Opportunities\ConfidenceReader;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Shared\DateRange;

final class InMemoryConfidence implements ConfidenceReader
{
    /** @var array<int, list<ConfidenceObservation>> */
    public array $rows = [];

    public function observations(int $projectId, SerpDevice $device, DateRange $range): array
    {
        return array_slice(array_values(array_filter($this->rows[$projectId] ?? [], static fn(ConfidenceObservation $r): bool => $range->contains($r->date))), 0, 2000);
    }
}
