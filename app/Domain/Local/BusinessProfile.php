<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Local;

/**
 * A business's Google Business Profile as Google shows it.
 */
final readonly class BusinessProfile
{
    public function __construct(
        public string $title,
        public ?string $category = null,
        public ?float $rating = null,
        public ?int $votes = null,
        public ?bool $claimed = null,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $url = null,
        public ?int $photos = null,
        public ?string $cid = null,
    ) {}
}
