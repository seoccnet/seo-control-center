<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * One stored check of a keyword (history view).
 */
final readonly class RankCheck
{
    /**
     * @param list<string> $features
     * @param list<array{position: int, domain: string, url: string}> $top
     */
    public function __construct(
        public string $date,
        public ?int $position,
        public ?string $url,
        public array $features,
        public array $top,
    ) {}
}
