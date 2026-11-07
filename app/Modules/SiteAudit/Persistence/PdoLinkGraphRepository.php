<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\InternalLinks\LinkGraphRepository;

final readonly class PdoLinkGraphRepository implements LinkGraphRepository
{
    private const int CHUNK = 200;
    private const string INDEXABLE = "p.state = 'done' AND p.status_code = 200 AND p.noindex = 0 AND p.content_type LIKE '%html%'";

    public function __construct(private Connection $db) {}

    public function pruneText(int $projectId, int $keepAuditId): void
    {
        foreach ($this->db->fetchAll("SELECT id FROM site_audits WHERE project_id = ? AND id <> ? AND status NOT IN ('queued', 'running')", [$projectId, $keepAuditId]) as $row) {
            foreach (['link_suggestions', 'site_audit_paragraphs', 'site_audit_link_contexts'] as $table) {
                while ($this->db->execute('DELETE FROM ' . $table . ' WHERE audit_id = ? LIMIT 5000', [(int) $row['id']]) > 0) {
                }
            }
        }
    }

    public function hasLinkText(int $auditId): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM site_audit_links WHERE audit_id = ? AND place IS NOT NULL LIMIT 1', [$auditId]) !== null;
    }

    public function latestCompleted(): array
    {
        return array_map(static fn(array $r): array => ['audit_id' => (int) $r['id'], 'project_id' => (int) $r['project_id'], 'workspace_id' => (int) $r['workspace_id']], $this->db->fetchAll(
            "SELECT a.id, a.project_id, a.workspace_id FROM site_audits a
               JOIN projects p ON p.id = a.project_id AND p.workspace_id = a.workspace_id AND p.deleted_at IS NULL
               JOIN workspaces w ON w.id = a.workspace_id AND w.deleted_at IS NULL
              WHERE a.status = 'completed'
                AND a.id = (SELECT MAX(b.id) FROM site_audits b WHERE b.project_id = a.project_id AND b.status = 'completed')
              ORDER BY a.id LIMIT 500",
        ));
    }

    public function graphPages(int $auditId): array
    {
        $pages = [];
        foreach ($this->db->fetchAll(
            'SELECT p.id, p.url, p.title, (SELECT COUNT(*) FROM site_audit_links l WHERE l.audit_id = p.audit_id AND l.to_page_id = p.id) AS inbound
               FROM site_audit_pages p WHERE p.audit_id = ? AND ' . self::INDEXABLE,
            [$auditId],
        ) as $row) {
            $pages[(int) $row['id']] = ['url' => (string) $row['url'], 'inbound' => (int) $row['inbound'], 'title' => $row['title'] !== null ? (string) $row['title'] : null];
        }

        return $pages;
    }

    public function paragraphs(int $auditId): array
    {
        return array_map(static fn(array $r): array => ['page_id' => (int) $r['page_id'], 'text' => (string) $r['text']], $this->db->fetchAll(
            'SELECT page_id, text FROM site_audit_paragraphs WHERE audit_id = ? ORDER BY page_id, position LIMIT 60000',
            [$auditId],
        ));
    }

    public function linked(int $auditId): array
    {
        $linked = [];
        foreach ($this->db->fetchAll("SELECT from_page_id, to_page_id FROM site_audit_links WHERE audit_id = ? AND place = 'body' AND (link_class IS NULL OR link_class NOT IN ('related', 'product', 'template'))", [$auditId]) as $row) {
            $linked[$row['from_page_id'] . ':' . $row['to_page_id']] = true;
        }

        return $linked;
    }

    public function builtAt(int $auditId): ?string
    {
        $value = $this->db->fetchValue('SELECT links_built_at FROM site_audits WHERE id = ?', [$auditId]);

        return $value !== null ? (string) $value : null;
    }

    public function leftOut(int $auditId): ?int
    {
        $value = $this->db->fetchValue('SELECT links_left_out FROM site_audits WHERE id = ?', [$auditId]);

        return $value !== null ? (int) $value : null;
    }

    public function replaceSuggestions(int $auditId, int $projectId, array $rows, string $now, int $leftOut = 0): void
    {
        $this->db->transaction(function () use ($auditId, $projectId, $rows, $now, $leftOut): void {
            $this->db->execute('DELETE FROM link_suggestions WHERE audit_id = ? AND project_id = ?', [$auditId, $projectId]);
            $this->db->execute('UPDATE site_audits SET links_built_at = ?, links_left_out = ? WHERE id = ? AND project_id = ?', [$now, $leftOut, $auditId, $projectId]);
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $this->db->execute(
                    'INSERT IGNORE INTO link_suggestions (audit_id, project_id, source_page_id, target_page_id, cluster_id, topic, anchor, paragraph, score, created_at) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                    array_merge(...array_map(static fn(array $r): array => [$auditId, $projectId, $r['source_page_id'], $r['target_page_id'], $r['cluster_id'], mb_substr($r['topic'], 0, 255), mb_substr($r['anchor'], 0, 160), mb_substr($r['paragraph'], 0, 400), $r['score'], $now], $chunk)),
                );
            }
        });
    }

    public function suggestions(int $projectId, int $auditId, string $search, int $offset, int $limit): array
    {
        [$where, $args] = self::search($projectId, $auditId, $search);

        return array_map(static fn(array $r): array => [
            'source_url' => (string) $r['source_url'],
            'target_url' => (string) $r['target_url'],
            'cluster_id' => $r['cluster_id'] !== null ? (string) $r['cluster_id'] : null,
            'topic' => (string) $r['topic'],
            'anchor' => (string) $r['anchor'],
            'paragraph' => (string) $r['paragraph'],
            'score' => (float) $r['score'],
        ], $this->db->fetchAll(
            sprintf('SELECT s.cluster_id, s.topic, s.anchor, s.paragraph, s.score, src.url AS source_url, dst.url AS target_url %s ORDER BY s.score DESC, s.id LIMIT %d OFFSET %d', $where, max(1, min(200, $limit)), max(0, $offset)),
            $args,
        ));
    }

    public function countSuggestions(int $projectId, int $auditId, string $search): int
    {
        [$where, $args] = self::search($projectId, $auditId, $search);

        return (int) $this->db->fetchValue('SELECT COUNT(*) ' . $where, $args);
    }

    public function pageLinks(int $auditId, int $pageId, string $direction, int $limit): array
    {
        [$own, $other] = $direction === 'in' ? ['to_page_id', 'from_page_id'] : ['from_page_id', 'to_page_id'];

        return array_map(static fn(array $r): array => [
            'url' => (string) $r['url'],
            'anchor' => $r['anchor'] !== null ? (string) $r['anchor'] : null,
            'place' => $r['place'] !== null ? (string) $r['place'] : null,
            'class' => $r['link_class'] !== null ? (string) $r['link_class'] : null,
            'confidence' => $r['link_confidence'] !== null ? (string) $r['link_confidence'] : null,
            'context' => $r['context'] !== null ? (string) $r['context'] : null,
        ], $this->db->fetchAll(
            sprintf(
                "SELECT p.url, l.anchor, l.place, l.link_class, l.link_confidence, c.context FROM site_audit_links l
                   JOIN site_audit_pages p ON p.id = l.%s AND p.audit_id = l.audit_id
                   LEFT JOIN site_audit_link_contexts c ON c.audit_id = l.audit_id AND c.from_page_id = l.from_page_id AND c.to_page_id = l.to_page_id
                  WHERE l.audit_id = ? AND l.%s = ?
                  ORDER BY l.link_class = 'editorial' DESC, l.place = 'body' DESC, p.url LIMIT %d",
                $other,
                $own,
                max(1, min(500, $limit)),
            ),
            [$auditId, $pageId],
        ));
    }

    /** @return array{string, list<mixed>} */
    private static function search(int $projectId, int $auditId, string $search): array
    {
        $where = 'FROM link_suggestions s
            JOIN site_audit_pages src ON src.id = s.source_page_id AND src.audit_id = s.audit_id
            JOIN site_audit_pages dst ON dst.id = s.target_page_id AND dst.audit_id = s.audit_id
           WHERE s.project_id = ? AND s.audit_id = ?';
        $args = [$projectId, $auditId];
        if ($search !== '') {
            $like = '%' . strtr($search, ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';
            $where .= ' AND (s.topic LIKE ? OR src.url LIKE ? OR dst.url LIKE ?)';
            array_push($args, $like, $like, $like);
        }

        return [$where, $args];
    }
}
