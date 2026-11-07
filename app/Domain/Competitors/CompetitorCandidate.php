<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Competitors;

/**
 * A domain that often ranks in the top 10 for the project's keywords.
 */
final readonly class CompetitorCandidate
{
    public function __construct(
        public string $domain,
        public int $keywords,
        public float $averagePosition,
        public int $top3,
    ) {}
}
