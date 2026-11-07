<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Research;

/**
 * A question an AI platform answered, mentioning the looked-up domain: an
 * excerpt of the answer, where the domain ranks among the sources and the
 * other cited domains.
 */
final readonly class AiAnswer
{
    public const int EXCERPT = 300;

    /**
     * @param list<string> $sources cited domains in order
     */
    public function __construct(
        public string $platform,
        public string $question,
        public ?string $excerpt,
        public ?int $aiSearchVolume,
        public ?int $sourceRank,
        public ?string $sourceUrl,
        public array $sources,
        public ?string $lastSeen,
    ) {}

    /**
     * @return array{p: string, q: string, a: ?string, v: ?int, r: ?int, u: ?string, s: list<string>, l: ?string}
     */
    public function toArray(): array
    {
        return ['p' => $this->platform, 'q' => $this->question, 'a' => $this->excerpt, 'v' => $this->aiSearchVolume, 'r' => $this->sourceRank, 'u' => $this->sourceUrl, 's' => $this->sources, 'l' => $this->lastSeen];
    }

    /**
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        if (!is_string($row['p'] ?? null) || !is_string($row['q'] ?? null)) {
            return null;
        }

        return new self(
            $row['p'],
            $row['q'],
            is_string($row['a'] ?? null) ? $row['a'] : null,
            is_int($row['v'] ?? null) ? $row['v'] : null,
            is_int($row['r'] ?? null) ? $row['r'] : null,
            is_string($row['u'] ?? null) ? $row['u'] : null,
            is_array($row['s'] ?? null) ? array_values(array_filter($row['s'], is_string(...))) : [],
            is_string($row['l'] ?? null) ? $row['l'] : null,
        );
    }
}
