<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors;

/** Reads the page URLs a competitor's sitemaps list (1.6.0). */
interface CompetitorSitemapReader
{
    /**
     * @return array{urls: list<string>, capped: bool} capped: the limit was reached, so the list may be incomplete
     * @throws \RuntimeException when no sitemap could be read
     */
    public function read(string $domain, int $limit): array;
}
