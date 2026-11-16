<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth;

/** Guarded requests to a project's own site for health checks (1.3.0). */
interface SiteProbe
{
    /**
     * One GET without following redirects.
     *
     * @return array{status: ?int, body: string, ms: ?int, error: ?string}
     */
    public function get(string $url, int $maxBytes): array;

    /** How many page URLs the site's sitemaps list (up to $limit), given its robots.txt text. */
    public function sitemapUrls(string $host, string $robotsText, int $limit): int;
}
