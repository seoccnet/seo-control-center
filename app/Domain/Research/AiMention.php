<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Research;

/**
 * How often an AI platform mentions a domain, and the AI search volume of
 * the questions it is mentioned for.
 */
final readonly class AiMention
{
    public function __construct(
        public string $domain,
        public string $platform,
        public int $mentions,
        public ?int $aiSearchVolume = null,
    ) {}

    /**
     * @return array{d: string, p: string, m: int, v: ?int}
     */
    public function toArray(): array
    {
        return ['d' => $this->domain, 'p' => $this->platform, 'm' => $this->mentions, 'v' => $this->aiSearchVolume];
    }

    /**
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        if (!is_string($row['d'] ?? null) || !is_string($row['p'] ?? null) || !is_int($row['m'] ?? null)) {
            return null;
        }

        return new self($row['d'], $row['p'], $row['m'], is_int($row['v'] ?? null) ? $row['v'] : null);
    }
}
