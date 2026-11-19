<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Backlinks\OutreachRepository;

final readonly class PdoOutreachRepository implements OutreachRepository
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function entries(int $projectId): array
    {
        return array_map(static fn(array $r): array => [
            'domain' => (string) $r['domain'],
            'source' => (string) $r['source'],
            'pageUrl' => $r['page_url'] !== null ? (string) $r['page_url'] : null,
            'targetUrl' => $r['target_url'] !== null ? (string) $r['target_url'] : null,
            'status' => (string) $r['status'],
            'note' => $r['note'] !== null ? (string) $r['note'] : null,
            'addedAt' => (string) $r['added_at'],
            'updatedAt' => (string) $r['updated_at'],
        ], $this->db->fetchAll(
            'SELECT domain, source, page_url, target_url, status, note, added_at, updated_at
             FROM link_outreach WHERE project_id = ? ORDER BY updated_at DESC, domain',
            [$projectId],
        ));
    }

    public function count(int $projectId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM link_outreach WHERE project_id = ?', [$projectId]);
    }

    public function add(int $projectId, int $workspaceId, string $domain, string $source, ?string $pageUrl, ?string $targetUrl, int $userId): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        return $this->db->execute(
            'INSERT IGNORE INTO link_outreach (project_id, domain, workspace_id, source, page_url, target_url, status, added_by, added_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$projectId, $domain, $workspaceId, $source, $pageUrl, $targetUrl, 'to_contact', $userId, $now, $now],
        ) > 0;
    }

    public function update(int $projectId, string $domain, string $status, ?string $note): bool
    {
        if ($this->db->fetchValue('SELECT 1 FROM link_outreach WHERE project_id = ? AND domain = ?', [$projectId, $domain]) === null) {
            return false;
        }
        $this->db->execute(
            'UPDATE link_outreach SET status = ?, note = ?, updated_at = ? WHERE project_id = ? AND domain = ?',
            [$status, $note, $this->clock->now()->format('Y-m-d H:i:s'), $projectId, $domain],
        );

        return true;
    }

    public function remove(int $projectId, string $domain): bool
    {
        return $this->db->execute('DELETE FROM link_outreach WHERE project_id = ? AND domain = ?', [$projectId, $domain]) > 0;
    }
}
