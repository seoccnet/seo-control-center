<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;

/**
 * The outcome of fetching one URL, plus the internal URLs it links or
 * redirects to.
 */
final readonly class PageResult
{
    /**
     * @param list<string> $discovered internal URLs to add to the crawl
     * @param list<array{url: string, kind: string}> $resources
     */
    public function __construct(
        public string $state,
        public ?int $statusCode = null,
        public ?string $contentType = null,
        public ?string $redirectTo = null,
        public ?int $durationMs = null,
        public ?int $bytes = null,
        public bool $truncated = false,
        public ?HtmlPage $page = null,
        public bool $noindex = false,
        public int $internalLinks = 0,
        public int $externalLinks = 0,
        public ?string $error = null,
        public array $discovered = [],
        public array $resources = [],
    ) {}
}
