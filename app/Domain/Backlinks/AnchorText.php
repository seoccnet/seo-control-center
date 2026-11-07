<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Backlinks;

/**
 * A link text used in backlinks to the target.
 */
final readonly class AnchorText
{
    public function __construct(
        public string $anchor,
        public int $backlinks,
        public ?int $referringDomains = null,
        public ?string $firstSeen = null,
    ) {}

    /**
     * @return array{a: string, b: int, rd: ?int, f: ?string}
     */
    public function toArray(): array
    {
        return ['a' => $this->anchor, 'b' => $this->backlinks, 'rd' => $this->referringDomains, 'f' => $this->firstSeen];
    }

    /**
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        if (!is_string($row['a'] ?? null) || !is_int($row['b'] ?? null)) {
            return null;
        }

        return new self($row['a'], $row['b'], is_int($row['rd'] ?? null) ? $row['rd'] : null, is_string($row['f'] ?? null) ? $row['f'] : null);
    }
}
