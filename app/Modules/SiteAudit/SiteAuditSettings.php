<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

/**
 * A project's audit settings: how many pages to crawl and whether to
 * audit the site every week.
 */
final readonly class SiteAuditSettings
{
    public const array PAGE_LIMITS = [100, 250, 500, 1000, 2000];
    public const int DEFAULT_PAGES = 500;

    public function __construct(
        public int $maxPages = self::DEFAULT_PAGES,
        public bool $weekly = false,
    ) {}
}
