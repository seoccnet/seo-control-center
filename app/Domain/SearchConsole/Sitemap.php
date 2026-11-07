<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * A sitemap as Search Console last processed it. Dates are UTC
 * ('Y-m-d H:i:s'); counts are what Google reported.
 */
final readonly class Sitemap
{
    public function __construct(
        public string $url,
        public bool $isIndex,
        public bool $isPending,
        public ?string $lastSubmitted,
        public ?string $lastDownloaded,
        public int $errors,
        public int $warnings,
        public int $submittedUrls,
    ) {}

    public function hasProblems(): bool
    {
        return $this->errors > 0 || $this->warnings > 0;
    }
}
