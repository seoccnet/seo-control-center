<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Research;

/**
 * Organic figures of a domain: from a domain overview (all its keywords)
 * or from competitor discovery (with the keywords it shares with the
 * project).
 */
final readonly class ResearchDomain
{
    public function __construct(
        public string $domain,
        public ?int $keywords = null,
        public ?int $top3 = null,
        public ?int $top10 = null,
        public ?float $traffic = null,
        public ?int $sharedKeywords = null,
        public ?float $averagePosition = null,
    ) {}

    /**
     * @return array{d: string, k: ?int, t3: ?int, t10: ?int, t: ?float, s: ?int, a: ?float}
     */
    public function toArray(): array
    {
        return ['d' => $this->domain, 'k' => $this->keywords, 't3' => $this->top3, 't10' => $this->top10, 't' => $this->traffic, 's' => $this->sharedKeywords, 'a' => $this->averagePosition];
    }

    /**
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        if (!is_string($row['d'] ?? null)) {
            return null;
        }
        $int = static fn(mixed $v): ?int => is_int($v) ? $v : null;
        $float = static fn(mixed $v): ?float => is_float($v) || is_int($v) ? (float) $v : null;

        return new self($row['d'], $int($row['k'] ?? null), $int($row['t3'] ?? null), $int($row['t10'] ?? null), $float($row['t'] ?? null), $int($row['s'] ?? null), $float($row['a'] ?? null));
    }
}
