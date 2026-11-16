<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors;

/**
 * Competitor content watch (1.6.0): the opt-in per project, the latest
 * sitemap read per competitor and the pages their sitemaps list.
 */
interface CompetitorWatchRepository
{
    public function enabled(int $projectId): bool;

    public function setEnabled(int $projectId, int $workspaceId, bool $enabled): void;

    /**
     * Competitors of watching projects not read on $today yet.
     *
     * @return list<array{competitorId: int, projectId: int, workspaceId: int, domain: string}>
     */
    public function due(string $today, int $limit): array;

    /**
     * @return array<int, array{checked_on: string, baseline_on: ?string, urls: ?int, capped: bool, error: ?string}> competitor id => latest read
     */
    public function states(int $projectId): array;

    /**
     * Stores one read. The first successful read is the baseline: its pages
     * are not new. Later reads add new pages (first seen today) and, unless
     * the read was capped, mark pages no longer listed as removed.
     *
     * @param list<string> $urls
     * @return array{added: int, removed: int}
     */
    public function record(int $competitorId, int $projectId, array $urls, string $today, bool $capped): array;

    public function fail(int $competitorId, int $projectId, string $today, string $error): void;

    /**
     * Pages first seen or removed on or after $since, newest first.
     *
     * @return list<array{competitorId: int, url: string, kind: string, date: string}> kind: new|removed
     */
    public function changes(int $projectId, string $since, int $limit): array;
}
