<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentInventory\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\ContentInventory\OverlapReviewRepository;
use SeoControlCenter\Modules\SiteAudit\AuditPage;

final readonly class PdoOverlapReviewRepository implements OverlapReviewRepository
{
    public function __construct(private Connection $db, private Clock $clock) {}

    public function reviews(int $projectId): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT candidate_key, kind, members, decision, retained_url, note, version, decided_by, decided_at FROM overlap_reviews WHERE project_id = ? ORDER BY id LIMIT 2000', [$projectId]) as $r) {
            $members = json_decode((string) $r['members'], true);
            $out[(string) $r['candidate_key']] = [
                'key' => (string) $r['candidate_key'],
                'kind' => (string) $r['kind'],
                'members' => is_array($members) ? array_values(array_filter($members, 'is_string')) : [],
                'decision' => (string) $r['decision'],
                'retained_url' => $r['retained_url'] !== null ? (string) $r['retained_url'] : null,
                'note' => (string) $r['note'],
                'version' => (int) $r['version'],
                'decided_by' => $r['decided_by'] !== null ? (int) $r['decided_by'] : null,
                'decided_at' => (string) $r['decided_at'],
            ];
        }

        return $out;
    }

    public function decide(int $workspaceId, int $projectId, string $key, string $kind, array $members, string $decision, ?string $retainedUrl, string $note, int $version, ?int $userId): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        if ($version === 0) {
            return $this->db->execute('INSERT IGNORE INTO overlap_reviews (workspace_id, project_id, candidate_key, kind, members, decision, retained_url, note, version, decided_by, decided_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)', [
                $workspaceId, $projectId, $key, $kind, json_encode($members, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $decision, $retainedUrl, $note, $userId, $now,
            ]) === 1;
        }

        return $this->db->execute('UPDATE overlap_reviews SET decision = ?, retained_url = ?, note = ?, version = version + 1, decided_by = ?, decided_at = ? WHERE project_id = ? AND candidate_key = ? AND version = ?', [
            $decision, $retainedUrl, $note, $userId, $now, $projectId, $key, $version,
        ]) === 1;
    }

    public function pageTexts(int $projectId, int $limit): array
    {
        $audit = $this->db->fetchOne("SELECT id, finished_at FROM site_audits WHERE project_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1", [$projectId]);
        if ($audit === null) {
            return ['audit_at' => null, 'pages' => []];
        }
        $pages = [];
        foreach ($this->db->fetchAll("SELECT id, url, title FROM site_audit_pages WHERE audit_id = ? AND state = 'done' AND status_code BETWEEN 200 AND 299 AND noindex = 0 ORDER BY id LIMIT " . max(1, $limit), [(int) $audit['id']]) as $r) {
            $pages[(int) $r['id']] = ['url' => (string) $r['url'], 'title' => $r['title'] !== null ? (string) $r['title'] : null, 'text' => ''];
        }
        foreach ($this->db->fetchAll('SELECT page_id, text FROM site_audit_paragraphs WHERE audit_id = ? ORDER BY page_id, position LIMIT 60000', [(int) $audit['id']]) as $r) {
            if (isset($pages[(int) $r['page_id']])) {
                $pages[(int) $r['page_id']]['text'] .= ' ' . (string) $r['text'];
            }
        }

        return ['audit_at' => $audit['finished_at'] !== null ? (string) $audit['finished_at'] : null, 'pages' => array_values($pages)];
    }

    public function productUrls(int $projectId): array
    {
        $audit = $this->db->fetchValue("SELECT id FROM site_audits WHERE project_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1", [$projectId]);
        if ($audit === null) {
            return [];
        }
        $urls = [];
        foreach ($this->db->fetchAll("SELECT url, og_type, schema_types FROM site_audit_pages WHERE audit_id = ? AND state = 'done' AND (og_type LIKE '%product%' OR schema_types LIKE '%Product%')", [(int) $audit]) as $r) {
            $page = new AuditPage(0, (string) $r['url'], 0, 'done', schemaTypes: $r['schema_types'] !== null ? explode(',', (string) $r['schema_types']) : null, ogType: $r['og_type'] !== null ? (string) $r['og_type'] : null);
            if ($page->isProduct()) {
                $urls[] = $page->url;
            }
        }

        return $urls;
    }
}
