<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Analytics;

final readonly class LandingPageMetrics
{
    public function __construct(public string $date, public string $url, public int $sessions, public float $conversions, public float $revenue) {}
    /** @return array{url: string, sessions: int, conversions: float, revenue: float} */
    public function toArray(): array
    {
        return ['url' => $this->url, 'sessions' => $this->sessions, 'conversions' => $this->conversions, 'revenue' => $this->revenue];
    }
}
