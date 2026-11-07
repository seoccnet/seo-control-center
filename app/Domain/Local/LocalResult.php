<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Local;

/**
 * One business in the Google Maps results for a keyword at a location.
 */
final readonly class LocalResult
{
    public function __construct(
        public int $position,
        public string $title,
        public ?string $domain = null,
        public ?string $url = null,
        public ?float $rating = null,
        public ?int $votes = null,
        public ?string $category = null,
        public ?string $cid = null,
        public ?string $address = null,
    ) {}

    /**
     * Whether this listing is the project's business: same Google ID, same
     * domain (or a subdomain), or the same name.
     */
    public function isBusiness(?string $cid, string $domain, string $name): bool
    {
        if ($cid !== null && $this->cid !== null) {
            return $cid === $this->cid;
        }
        $host = strtolower((string) $this->domain);
        if ($host !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
            return true;
        }

        return $name !== '' && mb_strtolower(trim($this->title)) === mb_strtolower(trim($name));
    }

    /**
     * @return array{p: int, t: string, d: ?string, r: ?float, v: ?int, c: ?string}
     */
    public function toArray(): array
    {
        return ['p' => $this->position, 't' => $this->title, 'd' => $this->domain, 'r' => $this->rating, 'v' => $this->votes, 'c' => $this->category];
    }

    /**
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        if (!is_int($row['p'] ?? null) || !is_string($row['t'] ?? null)) {
            return null;
        }

        return new self(
            $row['p'],
            $row['t'],
            is_string($row['d'] ?? null) ? $row['d'] : null,
            null,
            is_float($row['r'] ?? null) || is_int($row['r'] ?? null) ? (float) $row['r'] : null,
            is_int($row['v'] ?? null) ? $row['v'] : null,
            is_string($row['c'] ?? null) ? $row['c'] : null,
        );
    }
}
