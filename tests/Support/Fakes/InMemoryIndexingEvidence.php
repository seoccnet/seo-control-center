<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Modules\Indexing\IndexingEvidence;

final class InMemoryIndexingEvidence implements IndexingEvidence
{
    /** @var array<int, array<string, array{status_code: ?int, noindex: bool, canonical: ?string, redirect_to: ?string, error: ?string, crawled_at: ?string}>> */
    public array $crawls = [];
    /** @var array<int, array<string, array{first_seen_at: string, submitted_at: ?string}>> */
    public array $sitemaps = [];

    public function crawl(int $projectId, string $url): ?array
    {
        return $this->crawls[$projectId][$url] ?? null;
    }

    public function sitemap(int $projectId, string $url): ?array
    {
        return $this->sitemaps[$projectId][$url] ?? null;
    }
}
