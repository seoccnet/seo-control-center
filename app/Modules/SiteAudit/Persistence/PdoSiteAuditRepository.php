<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Modules\SiteAudit\PageResult;
use SeoControlCenter\Modules\SiteAudit\SiteAudit;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditSettings;

final readonly class PdoSiteAuditRepository implements SiteAuditRepository
{
    private const string AUDIT_COLUMNS = 'id, public_id, project_id, workspace_id, status, trigger_type, start_url, max_pages, pages_crawled,
        pages_found, health_score, errors, warnings, notices, error, created_at, started_at, finished_at, updated_at';
    private const string PAGE_COLUMNS = 'id, url, depth, state, status_code, content_type, redirect_to, duration_ms, bytes, truncated, title,
        description, h1_count, h1, canonical, noindex, word_count, internal_links, external_links, error, schema_types, og_type, has_author, source_links, citation_signals, business_signals';
    private const int CHUNK = 200;

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function settings(int $projectId): ?SiteAuditSettings
    {
        $row = $this->db->fetchOne('SELECT max_pages, weekly FROM site_audit_settings WHERE project_id = ?', [$projectId]);

        return $row !== null ? new SiteAuditSettings((int) $row['max_pages'], (bool) $row['weekly']) : null;
    }

    public function saveSettings(int $projectId, int $workspaceId, int $maxPages, bool $weekly, int $userId): void
    {
        $now = $this->now();
        $this->db->execute(
            'INSERT INTO site_audit_settings (project_id, workspace_id, max_pages, weekly, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE max_pages = VALUES(max_pages), weekly = VALUES(weekly), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$projectId, $workspaceId, $maxPages, $weekly ? 1 : 0, $userId, $now, $now],
        );
    }

    public function weeklyProjects(): array
    {
        return array_map(
            static fn(array $row): array => ['projectId' => (int) $row['project_id'], 'workspaceId' => (int) $row['workspace_id'], 'projectPublicId' => (string) $row['public_id']],
            $this->db->fetchAll(
                'SELECT s.project_id, s.workspace_id, p.public_id FROM site_audit_settings s JOIN projects p ON p.id = s.project_id AND p.workspace_id = s.workspace_id
                 WHERE s.weekly = 1 AND p.deleted_at IS NULL ORDER BY s.project_id',
            ),
        );
    }

    public function create(int $projectId, int $workspaceId, string $startUrl, int $maxPages, string $trigger, ?int $userId): SiteAudit
    {
        $now = $this->now();
        $publicId = Uuid::v7($this->clock);
        $this->db->transaction(function () use ($projectId, $workspaceId, $startUrl, $maxPages, $trigger, $userId, $now, $publicId): void {
            $this->db->execute(
                'INSERT INTO site_audits (public_id, project_id, workspace_id, status, trigger_type, start_url, max_pages, pages_found, started_by, created_at, updated_at)
                 VALUES (?, ?, ?, \'queued\', ?, ?, ?, 1, ?, ?, ?)',
                [$publicId, $projectId, $workspaceId, $trigger, $startUrl, $maxPages, $userId, $now, $now],
            );
            $this->db->execute(
                'INSERT INTO site_audit_pages (audit_id, url, url_hash, depth, state) VALUES (?, ?, ?, 0, \'pending\')',
                [$this->db->lastInsertId(), $startUrl, sha1($startUrl)],
            );
        });

        return $this->find($projectId, $publicId) ?? throw new \RuntimeException('The audit was not saved.');
    }

    public function find(int $projectId, string $publicId): ?SiteAudit
    {
        $row = $this->db->fetchOne('SELECT ' . self::AUDIT_COLUMNS . ' FROM site_audits WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]);

        return $row !== null ? self::audit($row) : null;
    }

    public function byId(int $auditId): ?SiteAudit
    {
        $row = $this->db->fetchOne('SELECT ' . self::AUDIT_COLUMNS . ' FROM site_audits WHERE id = ?', [$auditId]);

        return $row !== null ? self::audit($row) : null;
    }

    public function latest(int $projectId, ?string $status = null): ?SiteAudit
    {
        $row = $status === null
            ? $this->db->fetchOne('SELECT ' . self::AUDIT_COLUMNS . ' FROM site_audits WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId])
            : $this->db->fetchOne('SELECT ' . self::AUDIT_COLUMNS . ' FROM site_audits WHERE project_id = ? AND status = ? ORDER BY id DESC LIMIT 1', [$projectId, $status]);

        return $row !== null ? self::audit($row) : null;
    }

    public function history(int $projectId, int $limit): array
    {
        return array_map(
            self::audit(...),
            $this->db->fetchAll('SELECT ' . self::AUDIT_COLUMNS . ' FROM site_audits WHERE project_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$projectId]),
        );
    }

    public function markRunning(int $auditId): void
    {
        $now = $this->now();
        $this->db->execute(
            'UPDATE site_audits SET status = \'running\', started_at = COALESCE(started_at, ?), updated_at = ? WHERE id = ? AND status IN (\'queued\', \'running\')',
            [$now, $now, $auditId],
        );
    }

    public function pendingPages(int $auditId, int $limit): array
    {
        return array_map(
            self::page(...),
            $this->db->fetchAll('SELECT ' . self::PAGE_COLUMNS . ' FROM site_audit_pages WHERE audit_id = ? AND state = \'pending\' ORDER BY depth, id LIMIT ' . max(1, $limit), [$auditId]),
        );
    }

    public function crawlState(int $auditId): array
    {
        $json = $this->db->fetchValue('SELECT state FROM site_audit_crawl_state WHERE audit_id = ?', [$auditId]);
        if (!is_string($json)) {
            return [];
        }
        $state = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        return is_array($state) ? $state : [];
    }

    public function saveCrawlState(int $auditId, array $state): void
    {
        $this->db->transaction(function () use ($auditId, $state): void {
            if (!in_array($this->db->fetchValue('SELECT status FROM site_audits WHERE id = ? FOR UPDATE', [$auditId]), SiteAudit::ACTIVE, true)) {
                return;
            }
            $this->db->execute('INSERT INTO site_audit_crawl_state (audit_id, state) VALUES (?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state)', [$auditId, json_encode($state, JSON_THROW_ON_ERROR)]);
            $this->db->execute('UPDATE site_audits SET updated_at = ? WHERE id = ?', [$this->now(), $auditId]);
        });
    }

    public function seed(SiteAudit $audit, array $urls, int $limit): int
    {
        return $this->db->transaction(function () use ($audit, $urls, $limit): int {
            if (!in_array($this->db->fetchValue('SELECT status FROM site_audits WHERE id = ? FOR UPDATE', [$audit->id]), SiteAudit::ACTIVE, true)) {
                return 0;
            }
            $room = min($limit, $audit->maxPages) - (int) $this->db->fetchValue('SELECT COUNT(*) FROM site_audit_pages WHERE audit_id = ?', [$audit->id]);
            $added = 0;
            foreach ($urls as $url) {
                if ($room <= 0) {
                    break;
                }
                $inserted = $this->db->execute(
                    'INSERT IGNORE INTO site_audit_pages (audit_id, url, url_hash, depth, state) VALUES (?, ?, ?, ?, \'pending\')',
                    [$audit->id, $url, sha1($url), AuditPage::UNLINKED],
                );
                $room -= $inserted;
                $added += $inserted;
            }
            if ($added > 0) {
                $this->db->execute(
                    'UPDATE site_audits SET pages_found = (SELECT COUNT(*) FROM site_audit_pages WHERE audit_id = ?), updated_at = ? WHERE id = ?',
                    [$audit->id, $this->now(), $audit->id],
                );
            }

            return $added;
        });
    }

    public function savePage(SiteAudit $audit, AuditPage $page, PageResult $result): void
    {
        $now = $this->now();
        $html = $result->page;
        $this->db->transaction(function () use ($audit, $page, $result, $html, $now): void {
            $status = $this->db->fetchValue('SELECT status FROM site_audits WHERE id = ? FOR UPDATE', [$audit->id]);
            if (!in_array($status, SiteAudit::ACTIVE, true)) {
                return;
            }
            $this->db->execute(
                'UPDATE site_audit_pages SET state = ?, status_code = ?, content_type = ?, redirect_to = ?, duration_ms = ?, bytes = ?, truncated = ?,
                        title = ?, description = ?, h1_count = ?, h1 = ?, canonical = ?, noindex = ?, word_count = ?, internal_links = ?,
                        external_links = ?, error = ?, schema_types = ?, og_type = ?, has_author = ?, source_links = ?, citation_signals = ?, business_signals = ?, crawled_at = ?
                 WHERE id = ? AND audit_id = ?',
                [
                    $result->state, $result->statusCode, $result->contentType, $result->redirectTo !== null ? mb_substr($result->redirectTo, 0, 2048) : null,
                    $result->durationMs, $result->bytes, $result->truncated ? 1 : 0, $html?->title, $html?->description, $html?->h1Count, $html?->h1,
                    $html?->canonical, $result->noindex ? 1 : 0, $html?->wordCount, min(65535, $result->internalLinks), min(65535, $result->externalLinks),
                    $result->error !== null ? mb_substr($result->error, 0, 500) : null,
                    $html !== null ? self::typeList($html->schemaTypes) : null, $html?->ogType, $html !== null ? ($html->hasAuthor ? 1 : 0) : null,
                    $html !== null ? min(65535, $html->sourceLinks) : null,
                    $html !== null && $html->citation !== [] ? json_encode($html->citation, JSON_THROW_ON_ERROR) : null,
                    $html !== null && $html->business !== [] ? mb_substr(json_encode($html->business, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 0, 1000) : null,
                    $now, $page->id, $audit->id,
                ],
            );

            $targets = [];
            $room = 500 - (int) $this->db->fetchValue('SELECT COUNT(*) FROM site_audit_resources WHERE audit_id = ?', [$audit->id]);
            foreach (array_slice($result->resources, 0, 20) as $resource) {
                $hash = hash('sha256', $resource['url']);
                $id = $this->db->fetchValue('SELECT id FROM site_audit_resources WHERE audit_id = ? AND url_hash = UNHEX(?)', [$audit->id, $hash]);
                if ($id === null && $room > 0) {
                    $this->db->execute('INSERT IGNORE INTO site_audit_resources (audit_id, url_hash, url) VALUES (?, UNHEX(?), ?)', [$audit->id, $hash, $resource['url']]);
                    $id = $this->db->fetchValue('SELECT id FROM site_audit_resources WHERE audit_id = ? AND url_hash = UNHEX(?)', [$audit->id, $hash]);
                    --$room;
                }
                if ($id !== null) {
                    $this->db->execute('INSERT IGNORE INTO site_audit_resource_refs (audit_id, page_id, resource_id, kind) VALUES (?, ?, ?, ?)', [$audit->id, $page->id, $id, $resource['kind']]);
                }
            }
            foreach ($result->discovered as $url) {
                if ($url !== $page->url) {
                    $targets[sha1($url)] = $url;
                }
            }
            if ($targets !== []) {
                // The stored depth, which a link found since the page was queued may have lowered.
                $current = (int) ($this->db->fetchValue('SELECT depth FROM site_audit_pages WHERE id = ? AND audit_id = ?', [$page->id, $audit->id]) ?? $page->depth);
                // Below a page that the home page does not reach, the click depth is unknown too.
                $childDepth = $current === AuditPage::UNLINKED ? AuditPage::UNLINKED : min(AuditPage::UNLINKED - 1, $current + 1);
                $known = $this->idsByHash($audit->id, array_keys($targets));
                $room = $audit->maxPages - (int) $this->db->fetchValue('SELECT COUNT(*) FROM site_audit_pages WHERE audit_id = ?', [$audit->id]);
                foreach ($targets as $hash => $url) {
                    if (isset($known[$hash]) || $room <= 0) {
                        continue;
                    }
                    $this->db->execute(
                        'INSERT IGNORE INTO site_audit_pages (audit_id, url, url_hash, depth, state) VALUES (?, ?, ?, ?, \'pending\')',
                        [$audit->id, $url, $hash, $childDepth],
                    );
                    $room--;
                }
                $byHash = $this->idsByHash($audit->id, array_keys($targets));
                $ids = array_values(array_unique(array_values($byHash)));
                // Anchor, place and (for body links) the paragraph of each linked page (ADR-040, F1).
                $context = [];
                foreach ($byHash as $hash => $id) {
                    $context[$id] ??= $html?->linkContexts[$targets[$hash]] ?? null;
                }
                foreach (array_chunk($childDepth !== AuditPage::UNLINKED ? $ids : [], self::CHUNK) as $chunk) {
                    $this->db->execute(
                        'UPDATE site_audit_pages SET depth = ? WHERE audit_id = ? AND depth > ? AND id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                        [$childDepth, $audit->id, $childDepth, ...$chunk],
                    );
                }
                foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                    $this->db->execute(
                        'INSERT IGNORE INTO site_audit_links (audit_id, from_page_id, to_page_id, anchor, place, link_class, link_confidence) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?)')),
                        array_merge(...array_map(static fn(int $id): array => [$audit->id, $page->id, $id, ($context[$id]['anchor'] ?? '') !== '' ? $context[$id]['anchor'] : null, $context[$id]['place'] ?? null, $context[$id]['class'] ?? null, $context[$id]['confidence'] ?? null], $chunk)),
                    );
                }
                $withText = array_values(array_filter($ids, static fn(int $id): bool => ($context[$id]['context'] ?? null) !== null));
                foreach (array_chunk($withText, self::CHUNK) as $chunk) {
                    $this->db->execute(
                        'INSERT IGNORE INTO site_audit_link_contexts (audit_id, from_page_id, to_page_id, context) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)')),
                        array_merge(...array_map(static fn(int $id): array => [$audit->id, $page->id, $id, (string) ($context[$id]['context'] ?? '')], $chunk)),
                    );
                }
            }

            $paragraphs = $html === null ? [] : $html->paragraphs;
            if ($paragraphs !== []) {
                $this->db->execute(
                    'INSERT IGNORE INTO site_audit_paragraphs (audit_id, page_id, position, text) VALUES ' . implode(', ', array_fill(0, count($paragraphs), '(?, ?, ?, ?)')),
                    array_merge(...array_map(static fn(int $position, string $text): array => [$audit->id, $page->id, $position, $text], array_keys($paragraphs), $paragraphs)),
                );
            }

            $this->db->execute(
                'UPDATE site_audits SET pages_crawled = (SELECT COUNT(*) FROM site_audit_pages WHERE audit_id = ? AND state <> \'pending\'),
                        pages_found = (SELECT COUNT(*) FROM site_audit_pages WHERE audit_id = ?), updated_at = ?
                 WHERE id = ?',
                [$audit->id, $audit->id, $now, $audit->id],
            );
        });
    }

    public function allPages(int $auditId): array
    {
        return array_map(
            self::page(...),
            $this->db->fetchAll('SELECT ' . self::PAGE_COLUMNS . ' FROM site_audit_pages WHERE audit_id = ? ORDER BY id', [$auditId]),
        );
    }

    public function pendingResources(int $auditId): array
    {
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'url' => (string) $r['url']], $this->db->fetchAll('SELECT id, url FROM site_audit_resources WHERE audit_id = ? AND state = \'pending\' ORDER BY id LIMIT 10', [$auditId]));
    }

    public function saveResource(int $auditId, int $id, string $state, ?int $status): void
    {
        $this->db->transaction(function () use ($auditId, $id, $state, $status): void {
            if (!in_array($this->db->fetchValue('SELECT status FROM site_audits WHERE id = ? FOR UPDATE', [$auditId]), SiteAudit::ACTIVE, true)) {
                return;
            }
            $this->db->execute('UPDATE site_audit_resources SET state = ?, status_code = ? WHERE id = ? AND audit_id = ?', [$state, $status, $id, $auditId]);
            $this->db->execute('UPDATE site_audits SET updated_at = ? WHERE id = ? AND status = \'running\'', [$this->now(), $auditId]);
        });
    }

    public function resourceIssues(int $auditId): array
    {
        return \SeoControlCenter\Modules\SiteAudit\ResourceIssues::detect(array_map(static fn(array $r): array => ['pageId' => (int) $r['page_id'], 'url' => (string) $r['url'], 'kind' => (string) $r['kind'], 'state' => (string) $r['state'], 'status' => $r['status_code'] === null ? null : (int) $r['status_code']], $this->db->fetchAll('SELECT f.page_id, f.kind, r.url, r.state, r.status_code FROM site_audit_resource_refs f JOIN site_audit_resources r ON r.id = f.resource_id AND r.audit_id = f.audit_id WHERE f.audit_id = ? ORDER BY f.page_id, r.id', [$auditId])));
    }

    public function findPage(int $auditId, string $url): ?AuditPage
    {
        foreach ($this->db->fetchAll('SELECT ' . self::PAGE_COLUMNS . ' FROM site_audit_pages WHERE audit_id = ? AND url_hash = ?', [$auditId, sha1($url)]) as $row) {
            if ((string) $row['url'] === $url) {
                return $this->page($row);
            }
        }
        return null;
    }

    public function pageIssues(int $auditId, int $pageId): array
    {
        $result = [];
        foreach ($this->db->fetchAll('SELECT type, detail FROM site_audit_issues WHERE audit_id = ? AND page_id = ? ORDER BY type', [$auditId, $pageId]) as $r) {
            $type = IssueType::tryFrom((string) $r['type']);
            if ($type !== null) {
                $result[] = ['type' => $type, 'detail' => $r['detail'] !== null ? (string) $r['detail'] : null];
            }
        }
        return $result;
    }

    public function inboundCounts(int $auditId): array
    {
        $counts = [];
        foreach ($this->db->fetchAll('SELECT to_page_id, COUNT(*) AS n FROM site_audit_links WHERE audit_id = ? GROUP BY to_page_id', [$auditId]) as $row) {
            $counts[(int) $row['to_page_id']] = (int) $row['n'];
        }

        return $counts;
    }

    public function finish(int $auditId, string $status, ?int $score, array $counts, array $issues, ?string $error): void
    {
        $now = $this->now();
        $this->db->transaction(function () use ($auditId, $status, $score, $counts, $issues, $error, $now): void {
            $current = $this->db->fetchValue('SELECT status FROM site_audits WHERE id = ? FOR UPDATE', [$auditId]);
            if (!in_array($current, SiteAudit::ACTIVE, true)) {
                return;
            }
            $this->db->execute('DELETE FROM site_audit_issues WHERE audit_id = ?', [$auditId]);
            foreach (array_chunk($issues, self::CHUNK) as $chunk) {
                $this->db->execute(
                    'INSERT INTO site_audit_issues (audit_id, page_id, type, severity, detail) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)')),
                    array_merge(...array_map(static fn(array $i): array => [$auditId, $i['pageId'], $i['type']->value, $i['type']->severity(), $i['detail']], $chunk)),
                );
            }
            $this->db->execute(
                'UPDATE site_audits SET status = ?, health_score = ?, errors = ?, warnings = ?, notices = ?, error = ?, finished_at = ?, updated_at = ?,
                        pages_crawled = (SELECT COUNT(*) FROM site_audit_pages WHERE audit_id = ? AND state <> \'pending\')
                 WHERE id = ?',
                [$status, $score, $counts['error'], $counts['warning'], $counts['notice'], $error !== null ? mb_substr($error, 0, 500) : null, $now, $now, $auditId, $auditId],
            );
            if ($status !== 'completed') {
                // Unfinished crawl state is of no use once the audit has ended.
                $this->db->execute('DELETE FROM site_audit_pages WHERE audit_id = ? AND state = \'pending\'', [$auditId]);
            }
        });
    }

    public function issueCounts(int $auditId): array
    {
        $counts = [];
        foreach ($this->db->fetchAll('SELECT type, COUNT(*) AS n FROM site_audit_issues WHERE audit_id = ? GROUP BY type', [$auditId]) as $row) {
            $counts[(string) $row['type']] = (int) $row['n'];
        }

        return $counts;
    }

    public function issues(int $auditId, ?IssueType $type, int $offset, int $limit): array
    {
        $sql = 'SELECT i.page_id, p.url, i.type, i.detail FROM site_audit_issues i JOIN site_audit_pages p ON p.id = i.page_id AND p.audit_id = i.audit_id
                WHERE i.audit_id = ?' . ($type !== null ? ' AND i.type = ?' : '') . '
                ORDER BY FIELD(i.severity, \'error\', \'warning\', \'notice\'), i.type, p.url
                LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        $rows = [];
        foreach ($this->db->fetchAll($sql, $type !== null ? [$auditId, $type->value] : [$auditId]) as $row) {
            $issueType = IssueType::tryFrom((string) $row['type']);
            if ($issueType !== null) {
                $rows[] = ['pageId' => (int) $row['page_id'], 'url' => (string) $row['url'], 'type' => $issueType, 'detail' => $row['detail'] !== null ? (string) $row['detail'] : null];
            }
        }

        return $rows;
    }

    public function pagesById(int $auditId, array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }
        $pages = [];
        foreach ($this->db->fetchAll(
            'SELECT ' . self::PAGE_COLUMNS . ' FROM site_audit_pages WHERE audit_id = ? AND id IN (' . implode(', ', array_fill(0, count($pageIds), '?')) . ')',
            [$auditId, ...$pageIds],
        ) as $row) {
            $page = self::page($row);
            $pages[$page->id] = $page;
        }

        return $pages;
    }

    public function linkSources(int $auditId, int $pageId, int $limit): array
    {
        return array_map(
            static fn(array $row): string => (string) $row['url'],
            $this->db->fetchAll(
                'SELECT p.url FROM site_audit_links l JOIN site_audit_pages p ON p.id = l.from_page_id AND p.audit_id = l.audit_id
                 WHERE l.audit_id = ? AND l.to_page_id = ? ORDER BY p.url LIMIT ' . max(1, $limit),
                [$auditId, $pageId],
            ),
        );
    }

    public function pages(int $auditId, int $offset, int $limit): array
    {
        return array_map(
            self::page(...),
            $this->db->fetchAll(
                'SELECT ' . self::PAGE_COLUMNS . ' FROM site_audit_pages WHERE audit_id = ? AND state <> \'pending\' ORDER BY url LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
                [$auditId],
            ),
        );
    }

    public function prune(int $projectId, int $keep): void
    {
        $ids = $this->db->fetchAll(
            'SELECT id FROM site_audits WHERE project_id = ? AND status NOT IN (\'queued\', \'running\') ORDER BY id DESC LIMIT 1000 OFFSET ' . max(0, $keep),
            [$projectId],
        );
        foreach ($ids as $row) {
            // Children first, in chunks, so a large audit does not hold one huge delete.
            foreach (['link_suggestions', 'site_audit_paragraphs', 'site_audit_link_contexts', 'site_audit_issues', 'site_audit_links', 'site_audit_pages'] as $table) {
                while ($this->db->execute('DELETE FROM ' . $table . ' WHERE audit_id = ? LIMIT 5000', [(int) $row['id']]) > 0) {
                }
            }
            $this->db->execute('DELETE FROM site_audits WHERE id = ? AND project_id = ?', [(int) $row['id'], $projectId]);
        }
    }

    /**
     * @param list<string> $hashes
     * @return array<string, int> hash => page id
     */
    private function idsByHash(int $auditId, array $hashes): array
    {
        $ids = [];
        foreach (array_chunk($hashes, self::CHUNK) as $chunk) {
            $rows = $this->db->fetchAll(
                'SELECT id, url_hash FROM site_audit_pages WHERE audit_id = ? AND url_hash IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                [$auditId, ...$chunk],
            );
            foreach ($rows as $row) {
                $ids[(string) $row['url_hash']] = (int) $row['id'];
            }
        }

        return $ids;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function audit(array $row): SiteAudit
    {
        return new SiteAudit(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['project_id'],
            (int) $row['workspace_id'],
            (string) $row['status'],
            (string) $row['trigger_type'],
            (string) $row['start_url'],
            (int) $row['max_pages'],
            (int) $row['pages_crawled'],
            (int) $row['pages_found'],
            $row['health_score'] !== null ? (int) $row['health_score'] : null,
            (int) $row['errors'],
            (int) $row['warnings'],
            (int) $row['notices'],
            $row['error'] !== null ? (string) $row['error'] : null,
            (string) $row['created_at'],
            $row['started_at'] !== null ? (string) $row['started_at'] : null,
            $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
            (string) $row['updated_at'],
        );
    }

    /**
     * Comma-separated type names within the 500-character column, cut at a whole name.
     *
     * @param list<string> $types
     */
    private static function typeList(array $types): string
    {
        $list = '';
        foreach ($types as $type) {
            $next = $list === '' ? $type : $list . ',' . $type;
            if (strlen($next) > 500) {
                break;
            }
            $list = $next;
        }
        return $list;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function page(array $row): AuditPage
    {
        $int = static fn(mixed $v): ?int => $v !== null ? (int) $v : null;
        $str = static fn(mixed $v): ?string => $v !== null ? (string) $v : null;

        return new AuditPage(
            (int) $row['id'],
            (string) $row['url'],
            (int) $row['depth'],
            (string) $row['state'],
            $int($row['status_code']),
            $str($row['content_type']),
            $str($row['redirect_to']),
            $int($row['duration_ms']),
            $int($row['bytes']),
            (bool) $row['truncated'],
            $str($row['title']),
            $str($row['description']),
            $int($row['h1_count']),
            $str($row['h1']),
            $str($row['canonical']),
            (bool) $row['noindex'],
            $int($row['word_count']),
            (int) $row['internal_links'],
            (int) $row['external_links'],
            $str($row['error']),
            // Null for pages crawled before these columns existed: unknown, never "missing".
            array_key_exists('schema_types', $row) && $row['schema_types'] !== null ? array_values(array_filter(explode(',', (string) $row['schema_types']), static fn(string $t): bool => $t !== '')) : null,
            $str($row['og_type'] ?? null),
            array_key_exists('has_author', $row) && $row['has_author'] !== null ? (bool) $row['has_author'] : null,
            array_key_exists('source_links', $row) && $row['source_links'] !== null ? (int) $row['source_links'] : null,
            self::citation($row['citation_signals'] ?? null),
            self::business($row['business_signals'] ?? null),
        );
    }

    /** @return array{types: list<string>, name: ?string, phones: list<string>, address: ?string}|null */
    private static function business(mixed $json): ?array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return null;
        }
        $strings = static fn(mixed $v): array => array_values(array_filter(is_array($v) ? $v : [], 'is_string'));

        return ['types' => $strings($data['types'] ?? null), 'name' => is_string($data['name'] ?? null) ? $data['name'] : null, 'phones' => $strings($data['phones'] ?? null), 'address' => is_string($data['address'] ?? null) ? $data['address'] : null];
    }

    /** @return array{questions: int, tables: int, lists: int, figures: int, lead_words: ?int, updated: bool}|null */
    private static function citation(mixed $json): ?array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return null;
        }
        $int = static fn(string $key): int => is_int($data[$key] ?? null) ? max(0, $data[$key]) : 0;

        return ['questions' => $int('questions'), 'tables' => $int('tables'), 'lists' => $int('lists'), 'figures' => $int('figures'),
            'lead_words' => is_int($data['lead_words'] ?? null) ? $data['lead_words'] : null, 'updated' => ($data['updated'] ?? false) === true];
    }
}
