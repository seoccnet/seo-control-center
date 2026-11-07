<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Backlinks;

/**
 * The link profile of a domain: its rank (0-100), backlinks and referring
 * domains, the share of nofollow domains and the spam score.
 */
final readonly class BacklinkProfile
{
    public function __construct(
        public string $domain,
        public ?int $rank = null,
        public ?int $backlinks = null,
        public ?int $referringDomains = null,
        public ?int $nofollowDomains = null,
        public ?int $spamScore = null,
        public ?int $brokenBacklinks = null,
        public ?string $firstSeen = null,
    ) {}

    /**
     * @return array{d: string, r: ?int, b: ?int, rd: ?int, nf: ?int, s: ?int, br: ?int, f: ?string}
     */
    public function toArray(): array
    {
        return ['d' => $this->domain, 'r' => $this->rank, 'b' => $this->backlinks, 'rd' => $this->referringDomains, 'nf' => $this->nofollowDomains, 's' => $this->spamScore, 'br' => $this->brokenBacklinks, 'f' => $this->firstSeen];
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

        return new self($row['d'], $int($row['r'] ?? null), $int($row['b'] ?? null), $int($row['rd'] ?? null), $int($row['nf'] ?? null), $int($row['s'] ?? null), $int($row['br'] ?? null), is_string($row['f'] ?? null) ? $row['f'] : null);
    }
}
