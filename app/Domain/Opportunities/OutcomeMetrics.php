<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

final readonly class OutcomeMetrics
{
    public function __construct(public int $clicks, public int $impressions, public ?float $position) {}
    public function ctr(): ?float
    {
        return $this->impressions > 0 ? $this->clicks / $this->impressions : null;
    }
}
