<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Backlinks\DisavowRepository;

final readonly class PdoDisavowRepository implements DisavowRepository
{
    public function __construct(private Connection $db, private Clock $clock) {}

    public function domains(int $projectId): array
    {
        return array_map(static fn(array $r): array => [
            'domain' => (string) $r['domain'],
            'spamScore' => $r['spam_score'] !== null ? (int) $r['spam_score'] : null,
            'addedAt' => (string) $r['added_at'],
        ], $this->db->fetchAll('SELECT domain, spam_score, added_at FROM backlink_disavows WHERE project_id = ? ORDER BY domain', [$projectId]));
    }

    public function add(int $projectId, int $workspaceId, array $domains, int $userId): int
    {
        $room = self::MAX - (int) $this->db->fetchValue('SELECT COUNT(*) FROM backlink_disavows WHERE project_id = ?', [$projectId]);
        $added = 0;
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        foreach ($domains as $domain => $spam) {
            if ($added >= $room) {
                break;
            }
            $added += $this->db->execute(
                'INSERT IGNORE INTO backlink_disavows (project_id, domain, workspace_id, spam_score, added_by, added_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$projectId, (string) $domain, $workspaceId, $spam !== null ? max(0, min(100, $spam)) : null, $userId, $now],
            );
        }

        return $added;
    }

    public function remove(int $projectId, array $domains): int
    {
        $removed = 0;
        foreach (array_chunk(array_values(array_unique($domains)), 200) as $chunk) {
            $removed += $this->db->execute(
                'DELETE FROM backlink_disavows WHERE project_id = ? AND domain IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                [$projectId, ...$chunk],
            );
        }

        return $removed;
    }
}
