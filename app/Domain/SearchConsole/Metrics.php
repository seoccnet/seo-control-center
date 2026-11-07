<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * Clicks, impressions and average position for one row or an aggregate.
 * Position is impression-weighted, so aggregates must be weighted too.
 */
final readonly class Metrics
{
    public function __construct(
        public int $clicks,
        public int $impressions,
        public float $position,
    ) {
        if ($clicks < 0 || $impressions < 0 || $position < 0.0) {
            throw new \InvalidArgumentException('Search metrics cannot be negative.');
        }
    }

    public static function zero(): self
    {
        return new self(0, 0, 0.0);
    }

    /**
     * Click-through rate as a fraction (0.034 = 3.4 %).
     */
    public function ctr(): float
    {
        return $this->impressions > 0 ? $this->clicks / $this->impressions : 0.0;
    }

    public function isEmpty(): bool
    {
        return $this->impressions === 0 && $this->clicks === 0;
    }

    /**
     * @param iterable<self> $items
     */
    public static function sum(iterable $items): self
    {
        $clicks = 0;
        $impressions = 0;
        $weighted = 0.0;
        foreach ($items as $m) {
            $clicks += $m->clicks;
            $impressions += $m->impressions;
            $weighted += $m->position * $m->impressions;
        }

        return new self($clicks, $impressions, $impressions > 0 ? $weighted / $impressions : 0.0);
    }
}
