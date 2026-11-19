<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Rankings\KeywordTargetRepository;

final readonly class PdoKeywordTargetRepository implements KeywordTargetRepository
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function targets(int $projectId): array
    {
        $targets = [];
        foreach ($this->db->fetchAll(
            'SELECT pk.public_id, kt.url FROM keyword_targets kt
             JOIN project_keywords pk ON pk.id = kt.project_keyword_id AND pk.project_id = kt.project_id
             WHERE kt.project_id = ?',
            [$projectId],
        ) as $row) {
            $targets[(string) $row['public_id']] = (string) $row['url'];
        }

        return $targets;
    }

    public function set(int $projectId, array $targets, int $userId): int
    {
        if ($targets === []) {
            return 0;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        return $this->db->transaction(function () use ($projectId, $targets, $userId, $now): int {
            $changed = 0;
            foreach ($targets as $publicId => $url) {
                $id = $this->db->fetchValue('SELECT id FROM project_keywords WHERE project_id = ? AND public_id = ?', [$projectId, (string) $publicId]);
                if ($id === null) {
                    continue;
                }
                if ($url === null) {
                    $changed += $this->db->execute('DELETE FROM keyword_targets WHERE project_keyword_id = ? AND project_id = ?', [(int) $id, $projectId]) > 0 ? 1 : 0;
                    continue;
                }
                $this->db->execute(
                    'INSERT INTO keyword_targets (project_keyword_id, project_id, url, set_by, set_at) VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE url = VALUES(url), set_by = VALUES(set_by), set_at = VALUES(set_at)',
                    [(int) $id, $projectId, $url, $userId, $now],
                );
                ++$changed;
            }

            return $changed;
        });
    }
}
