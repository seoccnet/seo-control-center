<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Competitors;

/**
 * The project's and a competitor's position for one keyword on one check
 * day (null = not in the checked results).
 */
final readonly class KeywordComparison
{
    /** A competitor must be this many places ahead for a "gap". */
    public const int GAP_MARGIN = 10;

    public function __construct(
        public string $keywordPublicId,
        public string $keyword,
        public ?int $searchVolume,
        public ?int $own,
        public ?int $theirs,
        public ?string $theirUrl,
    ) {}

    /**
     * Positive = the project ranks better.
     */
    public function lead(): int
    {
        return ($this->theirs ?? 101) - ($this->own ?? 101);
    }

    /**
     * The competitor is on page one and the project is clearly behind (or absent).
     */
    public function isGap(): bool
    {
        return $this->theirs !== null && $this->theirs <= 10 && -$this->lead() >= self::GAP_MARGIN;
    }

    public function bothRank(): bool
    {
        return $this->own !== null && $this->theirs !== null;
    }
}
