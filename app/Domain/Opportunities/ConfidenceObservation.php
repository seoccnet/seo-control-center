<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/** Daily observations for one subject; null is unknown, never a zero sample. */
final readonly class ConfidenceObservation
{
    public function __construct(
        public string $kind,
        public string $key,
        public string $source,
        public ?int $impressions,
        public ?int $clicks,
        public ?float $position,
        public ?float $deviation,
        public int $days,
        public string $date,
    ) {}
}
