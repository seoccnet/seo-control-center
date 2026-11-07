<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Local;

/**
 * A ranking grid: the business's Google Maps position for one keyword at
 * size × size points around the centre, `spacing` metres apart. Points are
 * numbered row by row from the north-west corner.
 */
final readonly class LocalGrid
{
    public const array SIZES = [3, 5, 7];
    public const array SPACINGS = [250 => '250 m', 500 => '500 m', 1000 => '1 km', 2000 => '2 km', 5000 => '5 km'];
    private const float METRES_PER_DEGREE = 111320.0;

    /**
     * @param array<int, ?int> $positions point index => position (null = not in the results); missing = not checked yet
     */
    public function __construct(
        public int $keywordId,
        public string $checkedOn,
        public int $size,
        public int $spacing,
        public LocalArea $center,
        public array $positions,
        public string $status = 'done',
    ) {}

    public function points(): int
    {
        return $this->size * $this->size;
    }

    /**
     * The map point of each grid index.
     *
     * @return list<LocalArea>
     */
    public function areas(): array
    {
        $half = intdiv($this->size - 1, 2);
        $areas = [];
        for ($row = 0; $row < $this->size; $row++) {
            for ($col = 0; $col < $this->size; $col++) {
                $north = ($half - $row) * $this->spacing;
                $east = ($col - $half) * $this->spacing;
                $latitude = $this->center->latitude + $north / self::METRES_PER_DEGREE;
                $longitude = $this->center->longitude + $east / (self::METRES_PER_DEGREE * max(0.01, cos(deg2rad($this->center->latitude))));
                $areas[] = new LocalArea(round(max(-90, min(90, $latitude)), 6), round(fmod($longitude + 540, 360) - 180, 6), $this->center->zoom);
            }
        }

        return $areas;
    }

    /**
     * Positions row by row; null = not found, false = not checked yet.
     *
     * @return list<list<int|false|null>>
     */
    public function rows(): array
    {
        $rows = [];
        for ($row = 0; $row < $this->size; $row++) {
            $cells = [];
            for ($col = 0; $col < $this->size; $col++) {
                $index = $row * $this->size + $col;
                $cells[] = array_key_exists($index, $this->positions) ? $this->positions[$index] : false;
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    public function found(): int
    {
        return count(array_filter($this->positions, static fn(?int $p): bool => $p !== null));
    }

    /**
     * Average position over the checked points; a point where the business is
     * not in the results counts as 21 (one below the results read).
     */
    public function averagePosition(): ?float
    {
        if ($this->positions === []) {
            return null;
        }

        return round(array_sum(array_map(static fn(?int $p): int => $p ?? 21, $this->positions)) / count($this->positions), 1);
    }

    public function isComplete(): bool
    {
        return count($this->positions) >= $this->points();
    }
}
