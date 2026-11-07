<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Modules\SiteAudit\PageResult;
use SeoControlCenter\Modules\SiteAudit\SiteAudit;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditScheduler;
use SeoControlCenter\Modules\SiteAudit\SiteAuditSettings;

/**
 * Site audits, their pages, links and issues, and queued crawls in memory.
 */
final class InMemorySiteAudit implements SiteAuditRepository, SiteAuditScheduler
{
    /** @var array<int, array{settings: SiteAuditSettings, workspaceId: int, projectPublicId: string}> */
    public array $settings = [];

    /** @var array<int, SiteAudit> */
    public array $audits = [];

    /** @var array<int, array<int, AuditPage>> audit id => page id => page */
    public array $pages = [];

    /** @var array<int, array<string, array{0: int, 1: int}>> audit id => "from:to" => [from, to] */
    public array $links = [];

    /** @var array<int, list<array{pageId: int, type: IssueType, detail: ?string}>> */
    public array $issues = [];

    /** @var list<array{auditId: int, workspaceId: int}> */
    public array $queuedCrawls = [];

    /** @var list<array{projectId: int, workspaceId: int, requestId: string, delaySeconds: int}> */
    public array $queuedRenders = [];

    public function render(int $projectId, int $workspaceId, string $requestId, int $delaySeconds = 0): void
    {
        $this->queuedRenders[] = compact('projectId', 'workspaceId', 'requestId', 'delaySeconds');
    }

    /** @var array<int, string> project id => public id, for weeklyProjects() */
    public array $projectPublicIds = [];

    private int $nextAuditId = 1;
    private int $nextPageId = 1;

    /** @var array<int, array<string, mixed>> */
    public array $crawlStates = [];

    public function crawlState(int $auditId): array
    {
        return $this->crawlStates[$auditId] ?? [];
    }

    public function saveCrawlState(int $auditId, array $state): void
    {
        if ($this->byId($auditId)?->isActive()) {
            $this->crawlStates[$auditId] = $state;
            $this->audits[$auditId] = $this->with($this->audits[$auditId]);
        }
    }

    public function __construct(private readonly Clock $clock) {}

    public function crawl(int $auditId, int $workspaceId): void
    {
        $this->queuedCrawls[] = ['auditId' => $auditId, 'workspaceId' => $workspaceId];
    }

    public function settings(int $projectId): ?SiteAuditSettings
    {
        return $this->settings[$projectId]['settings'] ?? null;
    }

    public function saveSettings(int $projectId, int $workspaceId, int $maxPages, bool $weekly, int $userId): void
    {
        $this->settings[$projectId] = ['settings' => new SiteAuditSettings($maxPages, $weekly), 'workspaceId' => $workspaceId, 'projectPublicId' => $this->projectPublicIds[$projectId] ?? ''];
    }

    public function weeklyProjects(): array
    {
        $rows = [];
        foreach ($this->settings as $projectId => $row) {
            if ($row['settings']->weekly) {
                $rows[] = ['projectId' => $projectId, 'workspaceId' => $row['workspaceId'], 'projectPublicId' => $this->projectPublicIds[$projectId] ?? $row['projectPublicId']];
            }
        }

        return $rows;
    }

    public function create(int $projectId, int $workspaceId, string $startUrl, int $maxPages, string $trigger, ?int $userId): SiteAudit
    {
        $id = $this->nextAuditId++;
        $now = $this->now();
        $this->audits[$id] = new SiteAudit($id, Uuid::v7($this->clock), $projectId, $workspaceId, 'queued', $trigger, $startUrl, $maxPages, 0, 1, null, 0, 0, 0, null, $now, null, null, $now);
        $pageId = $this->nextPageId++;
        $this->pages[$id] = [$pageId => new AuditPage($pageId, $startUrl, 0, 'pending')];
        $this->links[$id] = [];

        return $this->audits[$id];
    }

    public function find(int $projectId, string $publicId): ?SiteAudit
    {
        foreach ($this->audits as $audit) {
            if ($audit->projectId === $projectId && $audit->publicId === $publicId) {
                return $audit;
            }
        }

        return null;
    }

    public function byId(int $auditId): ?SiteAudit
    {
        return $this->audits[$auditId] ?? null;
    }

    public function latest(int $projectId, ?string $status = null): ?SiteAudit
    {
        foreach ($this->history($projectId, PHP_INT_MAX) as $audit) {
            if ($status === null || $audit->status === $status) {
                return $audit;
            }
        }

        return null;
    }

    public function history(int $projectId, int $limit): array
    {
        $audits = array_values(array_filter($this->audits, static fn(SiteAudit $a): bool => $a->projectId === $projectId));
        usort($audits, static fn(SiteAudit $a, SiteAudit $b): int => $b->id <=> $a->id);

        return array_slice($audits, 0, $limit);
    }

    public function markRunning(int $auditId): void
    {
        $a = $this->audits[$auditId];
        if ($a->isActive()) {
            $this->audits[$auditId] = $this->with($a, status: 'running', startedAt: $a->startedAt ?? $this->now());
        }
    }

    public function pendingPages(int $auditId, int $limit): array
    {
        $pending = array_values(array_filter($this->pages[$auditId] ?? [], static fn(AuditPage $p): bool => $p->state === 'pending'));
        usort($pending, static fn(AuditPage $a, AuditPage $b): int => [$a->depth, $a->id] <=> [$b->depth, $b->id]);

        return array_slice($pending, 0, $limit);
    }

    public function seed(SiteAudit $audit, array $urls, int $limit): int
    {
        $added = 0;
        foreach ($urls as $url) {
            if (count($this->pages[$audit->id]) >= min($limit, $audit->maxPages)) {
                break;
            }
            if ($this->pageByUrl($audit->id, $url) === null) {
                $id = $this->nextPageId++;
                $this->pages[$audit->id][$id] = new AuditPage($id, $url, AuditPage::UNLINKED, 'pending');
                $added++;
            }
        }
        $this->audits[$audit->id] = $this->with($this->audits[$audit->id], pagesFound: count($this->pages[$audit->id]));

        return $added;
    }

    public function savePage(SiteAudit $audit, AuditPage $page, PageResult $result): void
    {
        if (!($this->byId($audit->id)?->isActive() ?? false)) {
            return;
        }
        foreach (array_slice($result->resources, 0, 20) as $r) {
            $key = $r['url'];
            if (!isset($this->resources[$audit->id][$key]) && count($this->resources[$audit->id] ?? []) >= 500) {
                continue;
            }
            $this->resources[$audit->id][$key] ??= ['id' => count($this->resources[$audit->id] ?? []) + 1, 'url' => $key, 'state' => 'pending', 'status' => null];
            $this->resourceRefs[$audit->id][$page->id . ':' . $r['kind'] . ':' . $key] = ['pageId' => $page->id, 'kind' => $r['kind'], 'url' => $key];
        }
        // The stored depth, which a link found since the page was queued may have lowered.
        $page = $this->pages[$audit->id][$page->id];
        $this->pages[$audit->id][$page->id] = $page->withResult($result);
        $childDepth = $page->isLinked() ? $page->depth + 1 : AuditPage::UNLINKED;
        foreach ($result->discovered as $url) {
            if ($url === $page->url) {
                continue;
            }
            $target = $this->pageByUrl($audit->id, $url);
            if ($target === null && count($this->pages[$audit->id]) < $audit->maxPages) {
                $id = $this->nextPageId++;
                $target = $this->pages[$audit->id][$id] = new AuditPage($id, $url, $childDepth, 'pending');
            }
            if ($target !== null) {
                $this->links[$audit->id][$page->id . ':' . $target->id] = [$page->id, $target->id];
                if ($target->depth > $childDepth) {
                    $t = $this->pages[$audit->id][$target->id];
                    $this->pages[$audit->id][$target->id] = new AuditPage($t->id, $t->url, $childDepth, $t->state, $t->statusCode, $t->contentType, $t->redirectTo, $t->durationMs, $t->bytes, $t->truncated, $t->title, $t->description, $t->h1Count, $t->h1, $t->canonical, $t->noindex, $t->wordCount, $t->internalLinks, $t->externalLinks, $t->error);
                }
            }
        }
        $this->audits[$audit->id] = $this->with($this->audits[$audit->id], pagesCrawled: $this->crawled($audit->id), pagesFound: count($this->pages[$audit->id]));
    }

    public function allPages(int $auditId): array
    {
        return array_values($this->pages[$auditId] ?? []);
    }

    /** @var array<int, array<string, array{id: int, url: string, state: string, status: ?int}>> */
    private array $resources = [];
    /** @var array<int, array<string, array{pageId: int, kind: string, url: string}>> */
    private array $resourceRefs = [];

    public function pendingResources(int $auditId): array
    {
        return array_slice(array_values(array_map(static fn(array $r): array => ['id' => $r['id'], 'url' => $r['url']], array_filter($this->resources[$auditId] ?? [], static fn(array $r): bool => $r['state'] === 'pending'))), 0, 10);
    }

    public function saveResource(int $auditId, int $id, string $state, ?int $status): void
    {
        foreach ($this->resources[$auditId] ?? [] as $key => $r) {
            if ($r['id'] === $id) {
                $this->resources[$auditId][$key] = [...$r, 'state' => $state, 'status' => $status];
            }
        }
    }

    public function resourceIssues(int $auditId): array
    {
        return \SeoControlCenter\Modules\SiteAudit\ResourceIssues::detect(array_values(array_map(fn(array $r): array => [...$r, 'state' => $this->resources[$auditId][$r['url']]['state'], 'status' => $this->resources[$auditId][$r['url']]['status']], $this->resourceRefs[$auditId] ?? [])));
    }

    public function findPage(int $auditId, string $url): ?AuditPage
    {
        foreach ($this->pages[$auditId] ?? [] as $page) {
            if ($page->url === $url) {
                return $page;
            }
        }
        return null;
    }

    public function pageIssues(int $auditId, int $pageId): array
    {
        return array_map(
            static fn(array $r): array => ['type' => $r['type'], 'detail' => $r['detail']],
            array_values(array_filter($this->issues[$auditId] ?? [], static fn(array $r): bool => $r['pageId'] === $pageId))
        );
    }

    public function inboundCounts(int $auditId): array
    {
        $counts = [];
        foreach ($this->links[$auditId] ?? [] as [, $to]) {
            $counts[$to] = ($counts[$to] ?? 0) + 1;
        }

        return $counts;
    }

    public function finish(int $auditId, string $status, ?int $score, array $counts, array $issues, ?string $error): void
    {
        if (!($this->byId($auditId)?->isActive() ?? false)) {
            return;
        }
        $this->issues[$auditId] = $issues;
        if ($status !== 'completed') {
            $this->pages[$auditId] = array_filter($this->pages[$auditId] ?? [], static fn(AuditPage $p): bool => $p->state !== 'pending');
        }
        $this->audits[$auditId] = $this->with(
            $this->audits[$auditId],
            status: $status,
            healthScore: $score,
            counts: $counts,
            error: $error,
            finishedAt: $this->now(),
            pagesCrawled: $this->crawled($auditId),
        );
    }

    public function issueCounts(int $auditId): array
    {
        $counts = [];
        foreach ($this->issues[$auditId] ?? [] as $issue) {
            $counts[$issue['type']->value] = ($counts[$issue['type']->value] ?? 0) + 1;
        }

        return $counts;
    }

    public function issues(int $auditId, ?IssueType $type, int $offset, int $limit): array
    {
        $rows = [];
        foreach ($this->issues[$auditId] ?? [] as $issue) {
            if ($type === null || $issue['type'] === $type) {
                $rows[] = ['pageId' => $issue['pageId'], 'url' => $this->pages[$auditId][$issue['pageId']]->url, 'type' => $issue['type'], 'detail' => $issue['detail']];
            }
        }
        usort($rows, static fn(array $a, array $b): int => [$a['type']->severityRank(), $a['type']->value, $a['url']] <=> [$b['type']->severityRank(), $b['type']->value, $b['url']]);

        return array_slice($rows, $offset, $limit);
    }

    public function pagesById(int $auditId, array $pageIds): array
    {
        return array_intersect_key($this->pages[$auditId] ?? [], array_flip($pageIds));
    }

    public function linkSources(int $auditId, int $pageId, int $limit): array
    {
        $urls = [];
        foreach ($this->links[$auditId] ?? [] as [$from, $to]) {
            if ($to === $pageId) {
                $urls[] = $this->pages[$auditId][$from]->url;
            }
        }
        sort($urls);

        return array_slice($urls, 0, $limit);
    }

    public function pages(int $auditId, int $offset, int $limit): array
    {
        $pages = array_values(array_filter($this->pages[$auditId] ?? [], static fn(AuditPage $p): bool => $p->state !== 'pending'));
        usort($pages, static fn(AuditPage $a, AuditPage $b): int => $a->url <=> $b->url);

        return array_slice($pages, $offset, $limit);
    }

    public function prune(int $projectId, int $keep): void
    {
        $finished = array_values(array_filter($this->history($projectId, PHP_INT_MAX), static fn(SiteAudit $a): bool => !$a->isActive()));
        foreach (array_slice($finished, $keep) as $audit) {
            unset($this->audits[$audit->id], $this->pages[$audit->id], $this->links[$audit->id], $this->issues[$audit->id]);
        }
    }

    /**
     * Moves an audit's last activity into the past (for stale-crawl tests).
     */
    public function age(int $auditId, string $updatedAt, ?string $createdAt = null): void
    {
        $a = $this->audits[$auditId];
        $this->audits[$auditId] = new SiteAudit($a->id, $a->publicId, $a->projectId, $a->workspaceId, $a->status, $a->trigger, $a->startUrl, $a->maxPages, $a->pagesCrawled, $a->pagesFound, $a->healthScore, $a->errors, $a->warnings, $a->notices, $a->error, $createdAt ?? $a->createdAt, $a->startedAt, $a->finishedAt, $updatedAt);
    }

    private function pageByUrl(int $auditId, string $url): ?AuditPage
    {
        foreach ($this->pages[$auditId] ?? [] as $page) {
            if ($page->url === $url) {
                return $page;
            }
        }

        return null;
    }

    private function crawled(int $auditId): int
    {
        return count(array_filter($this->pages[$auditId] ?? [], static fn(AuditPage $p): bool => $p->state !== 'pending'));
    }

    /**
     * @param array{error: int, warning: int, notice: int}|null $counts
     */
    private function with(
        SiteAudit $a,
        ?string $status = null,
        ?string $startedAt = null,
        ?int $pagesCrawled = null,
        ?int $pagesFound = null,
        ?int $healthScore = null,
        ?array $counts = null,
        ?string $error = null,
        ?string $finishedAt = null,
    ): SiteAudit {
        return new SiteAudit(
            $a->id,
            $a->publicId,
            $a->projectId,
            $a->workspaceId,
            $status ?? $a->status,
            $a->trigger,
            $a->startUrl,
            $a->maxPages,
            $pagesCrawled ?? $a->pagesCrawled,
            $pagesFound ?? $a->pagesFound,
            $healthScore ?? $a->healthScore,
            $counts['error'] ?? $a->errors,
            $counts['warning'] ?? $a->warnings,
            $counts['notice'] ?? $a->notices,
            $error ?? $a->error,
            $a->createdAt,
            $startedAt ?? $a->startedAt,
            $finishedAt ?? $a->finishedAt,
            $this->now(),
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
