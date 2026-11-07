<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentInventory;

/**
 * Reads the stored records the content inventory joins (ADR-042): the
 * latest completed crawl, sitemap URLs, Search Console pages, briefs,
 * articles published from ideas, topic-plan pages and topic owners. All
 * reads are bounded and from stored data only.
 */
interface InventorySources
{
    /**
     * @return array{records: list<array{source: string, url: ?string, ref: string, title: ?string, status: string, observed_at: ?string, detail: string, keyword?: ?string, target?: ?string, noindex?: bool, impressions?: int, clicks?: int}>, coverage: array<string, array{observed_at: ?string, count: int, limited: bool, note: string}>}
     */
    public function records(int $projectId): array;
}
