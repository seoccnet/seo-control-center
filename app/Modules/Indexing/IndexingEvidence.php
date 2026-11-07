<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing;

/**
 * What SEOcc itself observed about a URL, for its indexing history: the
 * latest completed site audit's fetch and the IndexNow sitemap record.
 */
interface IndexingEvidence
{
    /** @return array{status_code: ?int, noindex: bool, canonical: ?string, redirect_to: ?string, error: ?string, crawled_at: ?string}|null */
    public function crawl(int $projectId, string $url): ?array;

    /** @return array{first_seen_at: string, submitted_at: ?string}|null */
    public function sitemap(int $projectId, string $url): ?array;
}
