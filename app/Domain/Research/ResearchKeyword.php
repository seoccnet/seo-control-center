<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Research;

/**
 * A keyword from keyword research: its metrics and, where the source
 * reports one, the position, URL and estimated traffic of the domain it
 * was looked up for.
 */
final readonly class ResearchKeyword
{
    /**
     * @param list<string>|null $serpTypes SERP element types the provider saw for the search (e.g. ai_overview, featured_snippet), null when not reported
     * @param int|null $avgReferringDomains average referring domains of the top 10 results, when the provider reports it
     */
    public function __construct(
        public string $keyword,
        public ?int $searchVolume = null,
        public ?float $cpc = null,
        public ?int $difficulty = null,
        public ?string $intent = null,
        public ?int $position = null,
        public ?string $url = null,
        public ?float $traffic = null,
        public ?array $serpTypes = null,
        public ?int $avgReferringDomains = null,
    ) {}

    /**
     * @return array{k: string, v: ?int, c: ?float, d: ?int, i: ?string, p: ?int, u: ?string, t: ?float, s?: list<string>, r?: int}
     */
    public function toArray(): array
    {
        $row = ['k' => $this->keyword, 'v' => $this->searchVolume, 'c' => $this->cpc, 'd' => $this->difficulty, 'i' => $this->intent, 'p' => $this->position, 'u' => $this->url, 't' => $this->traffic];
        if ($this->serpTypes !== null) {
            $row['s'] = $this->serpTypes;
        }
        if ($this->avgReferringDomains !== null) {
            $row['r'] = $this->avgReferringDomains;
        }

        return $row;
    }

    /**
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        if (!is_string($row['k'] ?? null)) {
            return null;
        }

        return new self(
            $row['k'],
            is_int($row['v'] ?? null) ? $row['v'] : null,
            is_float($row['c'] ?? null) || is_int($row['c'] ?? null) ? (float) $row['c'] : null,
            is_int($row['d'] ?? null) ? $row['d'] : null,
            is_string($row['i'] ?? null) ? $row['i'] : null,
            is_int($row['p'] ?? null) ? $row['p'] : null,
            is_string($row['u'] ?? null) ? $row['u'] : null,
            is_float($row['t'] ?? null) || is_int($row['t'] ?? null) ? (float) $row['t'] : null,
            is_array($row['s'] ?? null) ? array_values(array_filter($row['s'], is_string(...))) : null,
            is_int($row['r'] ?? null) ? $row['r'] : null,
        );
    }
}
