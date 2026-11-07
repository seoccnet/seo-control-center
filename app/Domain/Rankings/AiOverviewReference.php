<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * One source cited in an AI Overview.
 */
final readonly class AiOverviewReference
{
    public function __construct(
        public string $url,
        public string $domain,
        public ?string $title,
    ) {}
}
