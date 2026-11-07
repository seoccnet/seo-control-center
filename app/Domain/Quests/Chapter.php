<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

final readonly class Chapter
{
    public function __construct(
        public int $number,
        public string $key,
        public string $title,
        public string $rank,
        public string $summary,
    ) {}
}
