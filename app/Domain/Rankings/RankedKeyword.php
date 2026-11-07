<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

use SeoControlCenter\Domain\Keywords\KeywordLocale;

/**
 * A tracked keyword with its latest ranking, the previous check and the best position.
 */
final readonly class RankedKeyword
{
    /**
     * @param list<string> $features
     */
    public function __construct(
        public string $publicId,
        public string $keyword,
        public KeywordLocale $locale,
        public ?string $checkedOn,
        public ?int $position,
        public ?int $previousPosition,
        public ?bool $previouslyChecked,
        public ?int $bestPosition,
        public ?string $url,
        public array $features,
        public ?int $searchVolume,
    ) {}

    /**
     * Positive = improved (moved up). Entering or leaving the results counts
     * as moving from/to position 101.
     */
    public function change(): ?int
    {
        if ($this->checkedOn === null || $this->previouslyChecked !== true) {
            return null;
        }

        return ($this->previousPosition ?? 101) - ($this->position ?? 101);
    }
}
