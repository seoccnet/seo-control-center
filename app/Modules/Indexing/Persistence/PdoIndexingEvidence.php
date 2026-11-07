<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Modules\Indexing\IndexingEvidence;

/** Read-only, like the content inventory's sources: the site audit and IndexNow tables, scoped by project. */
final readonly class PdoIndexingEvidence implements IndexingEvidence
{
    public function __construct(private Connection $db) {}

    public function crawl(int $projectId, string $url): ?array
    {
        $row = $this->db->fetchOne("SELECT p.status_code, p.noindex, p.canonical, p.redirect_to, p.error, p.crawled_at FROM site_audit_pages p WHERE p.audit_id = (SELECT id FROM site_audits WHERE project_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1) AND p.url_hash = ? AND p.state = 'done'", [$projectId, sha1($url)]);

        return $row === null ? null : ['status_code' => $row['status_code'] === null ? null : (int) $row['status_code'], 'noindex' => (bool) $row['noindex'], 'canonical' => $row['canonical'] === null ? null : (string) $row['canonical'], 'redirect_to' => $row['redirect_to'] === null ? null : (string) $row['redirect_to'], 'error' => $row['error'] === null ? null : (string) $row['error'], 'crawled_at' => $row['crawled_at'] === null ? null : (string) $row['crawled_at']];
    }

    public function sitemap(int $projectId, string $url): ?array
    {
        $row = $this->db->fetchOne('SELECT first_seen_at, submitted_at FROM indexnow_urls WHERE project_id = ? AND url_hash = ?', [$projectId, hash('sha256', $url, true)]);

        return $row === null ? null : ['first_seen_at' => (string) $row['first_seen_at'], 'submitted_at' => $row['submitted_at'] === null ? null : (string) $row['submitted_at']];
    }
}
