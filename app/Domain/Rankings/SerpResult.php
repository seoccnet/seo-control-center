<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * One organic result. `position` counts organic results only (1 = first
 * organic result); `absolutePosition` counts every element on the page.
 */
final readonly class SerpResult
{
    public function __construct(
        public int $position,
        public int $absolutePosition,
        public string $url,
        public string $domain,
        public ?string $title,
        public ?string $description,
    ) {}
}
