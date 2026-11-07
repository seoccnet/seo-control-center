<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility;

/**
 * A tracked keyword on one check day: its organic position, whether Google
 * showed an AI Overview, where the project was cited and which domains the
 * overview cited.
 */
final readonly class AiOverviewKeyword
{
    /**
     * @param list<string> $domains cited domains in order (latest check)
     */
    public function __construct(
        public string $publicId,
        public string $keyword,
        public ?int $organicPosition,
        public bool $present,
        public ?int $ownPosition,
        public ?string $ownUrl,
        public array $domains,
    ) {}
}
