<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Shared\DateRange;

interface ConfidenceReader
{
    /** Three bounded bulk reads: top 500 GSC queries/pages, 1,000 tracked queries.
     * @return list<ConfidenceObservation>
     */
    public function observations(int $projectId, SerpDevice $device, DateRange $range): array;
}
