<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow;

/**
 * IndexNow settings, the URLs seen in a project's sitemap and the
 * submission log, always scoped by project.
 */
interface IndexNowRepository
{
    public function settings(int $projectId): ?IndexNowSettings;

    public function create(int $projectId, int $workspaceId, string $key): void;

    /** $path is the normalized key path, "/" for the site root. */
    public function markVerified(int $projectId, string $host, string $path = '/'): void;

    public function saveSitemap(int $projectId, ?string $sitemapUrl, bool $autoSubmit): void;

    public function recordSitemapCheck(int $projectId, ?string $error): void;

    /**
     * Projects with automatic sitemap submissions switched on.
     *
     * @return list<array{projectId: int, workspaceId: int}>
     */
    public function autoSubmitProjects(): array;

    /**
     * @return array<string, ?string> url => lastmod of every URL seen so far
     */
    public function knownUrls(int $projectId): array;

    /**
     * Stores new URLs and lastmod changes; $submitted URLs get submitted_at = now.
     *
     * @param list<array{loc: string, lastmod: ?string}> $urls
     * @param list<string> $submitted
     */
    public function rememberUrls(int $projectId, array $urls, array $submitted): void;

    public function logSubmission(int $projectId, string $source, int $count, ?int $status, string $result, ?int $userId): void;

    /**
     * @return list<array{source: string, url_count: int, status_code: ?int, result: string, created_at: string}>
     */
    public function submissions(int $projectId, int $limit): array;

    public function delete(int $projectId): void;
}
