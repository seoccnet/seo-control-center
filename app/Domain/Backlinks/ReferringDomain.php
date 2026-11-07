<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Backlinks;

/**
 * A domain that links to the target (or, in a link gap, to competitors):
 * its rank (0-100), the number of links, spam score and when the links
 * were first seen or lost. `targets` counts the competitors it links to.
 */
final readonly class ReferringDomain
{
    public function __construct(
        public string $domain,
        public ?int $rank = null,
        public ?int $backlinks = null,
        public ?int $spamScore = null,
        public ?string $firstSeen = null,
        public ?string $lostDate = null,
        public ?int $targets = null,
    ) {}

    /**
     * @return array{d: string, r: ?int, b: ?int, s: ?int, f: ?string, l: ?string, t: ?int}
     */
    public function toArray(): array
    {
        return ['d' => $this->domain, 'r' => $this->rank, 'b' => $this->backlinks, 's' => $this->spamScore, 'f' => $this->firstSeen, 'l' => $this->lostDate, 't' => $this->targets];
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
        $str = static fn(mixed $v): ?string => is_string($v) ? $v : null;

        return new self($row['d'], $int($row['r'] ?? null), $int($row['b'] ?? null), $int($row['s'] ?? null), $str($row['f'] ?? null), $str($row['l'] ?? null), $int($row['t'] ?? null));
    }
}
