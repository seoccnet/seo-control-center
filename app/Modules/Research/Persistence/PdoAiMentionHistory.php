<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Research\AiMentionHistory;

final readonly class PdoAiMentionHistory implements AiMentionHistory
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function isWeekly(int $projectId): bool
    {
        return (bool) $this->db->fetchValue('SELECT weekly FROM ai_mention_settings WHERE project_id = ?', [$projectId]);
    }

    public function setWeekly(int $projectId, int $workspaceId, bool $weekly): void
    {
        $this->db->execute(
            'INSERT INTO ai_mention_settings (project_id, workspace_id, weekly, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE weekly = VALUES(weekly), updated_at = VALUES(updated_at)',
            [$projectId, $workspaceId, $weekly ? 1 : 0, $this->clock->now()->format('Y-m-d H:i:s')],
        );
    }

    public function dueWeekly(string $before): array
    {
        return array_map(
            static fn(array $row): array => ['projectId' => (int) $row['project_id'], 'workspaceId' => (int) $row['workspace_id'], 'projectPublicId' => (string) $row['public_id']],
            $this->db->fetchAll(
                'SELECT s.project_id, s.workspace_id, p.public_id FROM ai_mention_settings s
                 JOIN projects p ON p.id = s.project_id AND p.workspace_id = s.workspace_id AND p.deleted_at IS NULL
                 WHERE s.weekly = 1 AND (s.last_checked_on IS NULL OR s.last_checked_on <= ?) ORDER BY s.project_id',
                [$before],
            ),
        );
    }

    public function record(int $projectId, int $workspaceId, string $date, array $mentions): void
    {
        $this->db->transaction(function () use ($projectId, $workspaceId, $date, $mentions): void {
            $this->db->execute('DELETE FROM ai_mention_history WHERE project_id = ? AND checked_on = ?', [$projectId, $date]);
            foreach ($mentions as $m) {
                $this->db->execute(
                    'INSERT INTO ai_mention_history (project_id, checked_on, platform, domain, mentions, ai_search_volume) VALUES (?, ?, ?, ?, ?, ?)',
                    [$projectId, $date, $m->platform, mb_substr($m->domain, 0, 255), max(0, $m->mentions), $m->aiSearchVolume],
                );
            }
            $this->db->execute(
                'INSERT INTO ai_mention_settings (project_id, workspace_id, weekly, last_checked_on, updated_at) VALUES (?, ?, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE last_checked_on = VALUES(last_checked_on), updated_at = VALUES(updated_at)',
                [$projectId, $workspaceId, $date, $this->clock->now()->format('Y-m-d H:i:s')],
            );
        });
    }

    public function history(int $projectId, int $days): array
    {
        $dates = array_map(
            static fn(array $row): string => (string) $row['checked_on'],
            $this->db->fetchAll('SELECT DISTINCT checked_on FROM ai_mention_history WHERE project_id = ? ORDER BY checked_on DESC LIMIT ' . max(1, $days), [$projectId]),
        );
        if ($dates === []) {
            return [];
        }
        $byDate = array_fill_keys($dates, []);
        $rows = $this->db->fetchAll(
            'SELECT checked_on, platform, domain, mentions FROM ai_mention_history WHERE project_id = ? AND checked_on IN (' . implode(', ', array_fill(0, count($dates), '?')) . ')',
            [$projectId, ...$dates],
        );
        foreach ($rows as $row) {
            $byDate[(string) $row['checked_on']][(string) $row['domain']][(string) $row['platform']] = (int) $row['mentions'];
        }

        return array_map(static fn(string $date): array => ['date' => $date, 'mentions' => $byDate[$date]], $dates);
    }
}
