<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * A keyword whose position changed between two check days
 * (null position = not in the checked results).
 */
final readonly class RankingMove
{
    public function __construct(
        public string $keyword,
        public ?int $from,
        public ?int $to,
    ) {}

    /**
     * Positive = improved.
     */
    public function change(): int
    {
        return ($this->from ?? 101) - ($this->to ?? 101);
    }
}
