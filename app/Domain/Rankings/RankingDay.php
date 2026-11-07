<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * Pre-aggregated rankings of a project for one day and device.
 */
final readonly class RankingDay
{
    public function __construct(
        public string $date,
        public int $keywords,
        public int $ranked,
        public int $top3,
        public int $top10,
        public ?float $averagePosition,
        public float $visibility,
    ) {}

    /**
     * Aggregates one day's positions (null = not ranking) with search volumes.
     *
     * @param list<array{position: ?int, volume: ?int}> $keywords
     */
    public static function calculate(string $date, array $keywords): self
    {
        $positions = array_values(array_filter(array_column($keywords, 'position'), static fn(?int $p): bool => $p !== null));

        return new self(
            $date,
            count($keywords),
            count($positions),
            count(array_filter($positions, static fn(int $p): bool => $p <= 3)),
            count(array_filter($positions, static fn(int $p): bool => $p <= 10)),
            $positions !== [] ? round(array_sum($positions) / count($positions), 2) : null,
            Visibility::percent($keywords),
        );
    }
}
