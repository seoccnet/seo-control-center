<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Quests\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Quests\QuestProfile;
use SeoControlCenter\Domain\Quests\QuestProgressRepository;

final readonly class PdoQuestProgressRepository implements QuestProgressRepository
{
    public function __construct(private Connection $db, private Clock $clock) {}

    public function profile(int $userId, int $workspaceId): QuestProfile
    {
        $row = $this->db->fetchOne('SELECT hidden, seen_quests, checked_at FROM quest_profiles WHERE user_id = ? AND workspace_id = ?', [$userId, $workspaceId]);
        if ($row === null) {
            return new QuestProfile();
        }
        $seen = is_string($row['seen_quests']) ? json_decode($row['seen_quests'], true) : null;

        return new QuestProfile(
            (bool) $row['hidden'],
            is_array($seen) ? array_values(array_filter($seen, is_string(...))) : null,
            is_string($row['checked_at']) ? $row['checked_at'] : null,
        );
    }

    public function setHidden(int $userId, int $workspaceId, bool $hidden): void
    {
        $now = $this->now();
        $this->db->execute(
            'INSERT INTO quest_profiles (user_id, workspace_id, hidden, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE hidden = VALUES(hidden), updated_at = VALUES(updated_at)',
            [$userId, $workspaceId, $hidden ? 1 : 0, $now, $now],
        );
    }

    public function markSeen(int $userId, int $workspaceId, array $questKeys): void
    {
        $now = $this->now();
        $this->db->execute(
            'INSERT INTO quest_profiles (user_id, workspace_id, seen_quests, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE seen_quests = VALUES(seen_quests), updated_at = VALUES(updated_at)',
            [$userId, $workspaceId, json_encode(array_values($questKeys), JSON_THROW_ON_ERROR), $now, $now],
        );
    }

    public function markChecked(int $userId, int $workspaceId, string $at): void
    {
        $this->db->execute(
            'INSERT INTO quest_profiles (user_id, workspace_id, checked_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at)',
            [$userId, $workspaceId, $at, $at, $at],
        );
    }

    public function progress(int $userId, int $workspaceId): array
    {
        $progress = [];
        foreach ($this->db->fetchAll('SELECT quest_key, step_key, completed_at, skipped_at FROM quest_progress WHERE user_id = ? AND workspace_id = ?', [$userId, $workspaceId]) as $row) {
            $progress[(string) $row['quest_key']][(string) $row['step_key']] = [
                'completed' => is_string($row['completed_at']) ? $row['completed_at'] : null,
                'skipped' => is_string($row['skipped_at']) ? $row['skipped_at'] : null,
            ];
        }

        return $progress;
    }

    public function complete(int $userId, int $workspaceId, string $questKey, string $stepKey, string $at): void
    {
        $this->db->execute(
            'INSERT INTO quest_progress (user_id, workspace_id, quest_key, step_key, completed_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE completed_at = COALESCE(completed_at, VALUES(completed_at))',
            [$userId, $workspaceId, $questKey, $stepKey, $at],
        );
    }

    public function skip(int $userId, int $workspaceId, string $questKey, string $at): void
    {
        $this->db->execute(
            "INSERT INTO quest_progress (user_id, workspace_id, quest_key, step_key, skipped_at) VALUES (?, ?, ?, '', ?)
             ON DUPLICATE KEY UPDATE skipped_at = COALESCE(skipped_at, VALUES(skipped_at))",
            [$userId, $workspaceId, $questKey, $at],
        );
    }

    public function unskip(int $userId, int $workspaceId, string $questKey): void
    {
        $this->db->execute("UPDATE quest_progress SET skipped_at = NULL WHERE user_id = ? AND workspace_id = ? AND quest_key = ? AND step_key = ''", [$userId, $workspaceId, $questKey]);
    }

    public function ranks(int $userId, int $workspaceId): array
    {
        $ranks = [];
        foreach ($this->db->fetchAll('SELECT chapter, earned_at, announced_at FROM quest_ranks WHERE user_id = ? AND workspace_id = ?', [$userId, $workspaceId]) as $row) {
            $ranks[(int) $row['chapter']] = ['earned' => (string) $row['earned_at'], 'announced' => $row['announced_at'] !== null];
        }

        return $ranks;
    }

    public function earnRank(int $userId, int $workspaceId, int $chapter, string $at): void
    {
        $this->db->execute(
            'INSERT INTO quest_ranks (user_id, workspace_id, chapter, earned_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE earned_at = earned_at',
            [$userId, $workspaceId, $chapter, $at],
        );
    }

    public function announceRanks(int $userId, int $workspaceId, array $chapters, string $at): void
    {
        if ($chapters === []) {
            return;
        }
        $this->db->execute(
            'UPDATE quest_ranks SET announced_at = ? WHERE user_id = ? AND workspace_id = ? AND announced_at IS NULL AND chapter IN (' . implode(', ', array_fill(0, count($chapters), '?')) . ')',
            [$at, $userId, $workspaceId, ...$chapters],
        );
    }

    public function due(string $before, int $limit): array
    {
        $rows = $this->db->fetchAll(
            "SELECT p.user_id, p.workspace_id FROM quest_profiles p
             JOIN workspace_users wu ON wu.user_id = p.user_id AND wu.workspace_id = p.workspace_id AND wu.status = 'active'
             JOIN users u ON u.id = p.user_id AND u.status = 'active'
             WHERE p.checked_at IS NULL OR p.checked_at < ?
             ORDER BY p.checked_at, p.id LIMIT " . max(1, $limit),
            [$before],
        );

        return array_map(static fn(array $row): array => ['user_id' => (int) $row['user_id'], 'workspace_id' => (int) $row['workspace_id']], $rows);
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
