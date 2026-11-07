<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

/**
 * Site audit settings, audits, their pages, internal links and issues,
 * always scoped by project (audits) or by audit (everything inside one).
 */
interface SiteAuditRepository
{
    public function settings(int $projectId): ?SiteAuditSettings;

    public function saveSettings(int $projectId, int $workspaceId, int $maxPages, bool $weekly, int $userId): void;

    /**
     * Projects with the weekly audit switched on.
     *
     * @return list<array{projectId: int, workspaceId: int, projectPublicId: string}>
     */
    public function weeklyProjects(): array;

    /**
     * Creates a queued audit with $startUrl as its first page.
     */
    public function create(int $projectId, int $workspaceId, string $startUrl, int $maxPages, string $trigger, ?int $userId): SiteAudit;

    public function find(int $projectId, string $publicId): ?SiteAudit;

    public function byId(int $auditId): ?SiteAudit;

    /**
     * The newest audit of the project; with $status, the newest one in that status.
     */
    public function latest(int $projectId, ?string $status = null): ?SiteAudit;

    /**
     * @return list<SiteAudit> newest first
     */
    public function history(int $projectId, int $limit): array;

    public function markRunning(int $auditId): void;

    /** @return array<string, mixed> */
    public function crawlState(int $auditId): array;

    /** Saves only while the audit is active.
     * @param array<string, mixed> $state
     */
    public function saveCrawlState(int $auditId, array $state): void;

    /**
     * @return list<AuditPage> pages not fetched yet, shallowest first
     */
    public function pendingPages(int $auditId, int $limit): array;

    /**
     * Adds sitemap URLs as unlinked pending pages, up to $limit pages in
     * the audit in total.
     *
     * @param list<string> $urls
     * @return int pages added
     */
    public function seed(SiteAudit $audit, array $urls, int $limit): int;

    /**
     * Stores what was fetched for $page, adds the URLs it discovered (as
     * long as the audit has room) and records the links to them. A linked page gets the depth of the
     * shortest path found so far.
     */
    public function savePage(SiteAudit $audit, AuditPage $page, PageResult $result): void;

    /**
     * @return list<AuditPage>
     */
    public function allPages(int $auditId): array;

    /** @return list<array{id: int, url: string}> */
    public function pendingResources(int $auditId): array;

    public function saveResource(int $auditId, int $id, string $state, ?int $status): void;

    /** @return list<array{pageId: int, type: IssueType, detail: ?string}> */
    public function resourceIssues(int $auditId): array;

    public function findPage(int $auditId, string $url): ?AuditPage;

    /** @return list<array{type: IssueType, detail: ?string}> */
    public function pageIssues(int $auditId, int $pageId): array;

    /**
     * @return array<int, int> page id => number of pages linking to it
     */
    public function inboundCounts(int $auditId): array;

    /**
     * Ends the audit: completed (with its issues and score), failed or cancelled.
     *
     * @param list<array{pageId: int, type: IssueType, detail: ?string}> $issues
     * @param array{error: int, warning: int, notice: int} $counts
     */
    public function finish(int $auditId, string $status, ?int $score, array $counts, array $issues, ?string $error): void;

    /**
     * @return array<string, int> issue type => number of pages
     */
    public function issueCounts(int $auditId): array;

    /**
     * @return list<array{pageId: int, url: string, type: IssueType, detail: ?string}> errors first
     */
    public function issues(int $auditId, ?IssueType $type, int $offset, int $limit): array;

    /**
     * The crawled pages with these IDs (the ones listed under an issue).
     *
     * @param list<int> $pageIds
     * @return array<int, AuditPage> page id => page
     */
    public function pagesById(int $auditId, array $pageIds): array;

    /**
     * @return list<string> URLs of pages linking to $pageId
     */
    public function linkSources(int $auditId, int $pageId, int $limit): array;

    /**
     * @return list<AuditPage> fetched pages in URL order
     */
    public function pages(int $auditId, int $offset, int $limit): array;

    /**
     * Deletes all but the newest $keep finished audits of the project.
     */
    public function prune(int $projectId, int $keep): void;
}
