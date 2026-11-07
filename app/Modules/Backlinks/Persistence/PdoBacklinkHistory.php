<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Modules\Backlinks\BacklinkHistory;

final readonly class PdoBacklinkHistory implements BacklinkHistory
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function isMonthly(int $projectId): bool
    {
        return (bool) $this->db->fetchValue('SELECT monthly FROM backlink_settings WHERE project_id = ?', [$projectId]);
    }

    public function setMonthly(int $projectId, int $workspaceId, bool $monthly): void
    {
        $this->db->execute(
            'INSERT INTO backlink_settings (project_id, workspace_id, monthly, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE monthly = VALUES(monthly), updated_at = VALUES(updated_at)',
            [$projectId, $workspaceId, $monthly ? 1 : 0, $this->now()],
        );
    }

    public function monitorsLost(int $projectId): bool
    {
        return (bool) $this->db->fetchValue('SELECT monitor_lost FROM backlink_settings WHERE project_id = ?', [$projectId]);
    }

    public function setLostMonitoring(int $projectId, int $workspaceId, bool $enabled): void
    {
        $this->db->execute('INSERT INTO backlink_settings (project_id, workspace_id, monthly, monitor_lost, updated_at) VALUES (?, ?, 0, ?, ?)
            ON DUPLICATE KEY UPDATE monitor_lost = VALUES(monitor_lost), updated_at = VALUES(updated_at)', [$projectId, $workspaceId, $enabled ? 1 : 0, $this->now()]);
    }

    public function recordLostCheck(int $projectId, string $date): void
    {
        $this->db->execute('UPDATE backlink_settings SET last_lost_checked_on = ?, updated_at = ? WHERE project_id = ?', [$date, $this->now(), $projectId]);
    }

    public function dueLost(string $before): array
    {
        return array_map(
            static fn(array $r): array => ['projectId' => (int) $r['project_id'], 'workspaceId' => (int) $r['workspace_id'], 'projectPublicId' => (string) $r['public_id']],
            $this->db->fetchAll('SELECT s.project_id, s.workspace_id, p.public_id FROM backlink_settings s JOIN projects p ON p.id = s.project_id AND p.workspace_id = s.workspace_id
                WHERE p.deleted_at IS NULL AND s.monitor_lost = 1 AND (s.last_lost_checked_on IS NULL OR s.last_lost_checked_on <= ?) ORDER BY s.project_id', [$before])
        );
    }

    public function dueMonthly(string $before): array
    {
        return array_map(
            static fn(array $row): array => ['projectId' => (int) $row['project_id'], 'workspaceId' => (int) $row['workspace_id'], 'projectPublicId' => (string) $row['public_id']],
            $this->db->fetchAll(
                'SELECT s.project_id, s.workspace_id, p.public_id FROM backlink_settings s
                 JOIN projects p ON p.id = s.project_id AND p.workspace_id = s.workspace_id AND p.deleted_at IS NULL
                 WHERE s.monthly = 1 AND (s.last_checked_on IS NULL OR s.last_checked_on <= ?) ORDER BY s.project_id',
                [$before],
            ),
        );
    }

    public function record(int $projectId, int $workspaceId, string $date, array $profiles): void
    {
        $this->db->transaction(function () use ($projectId, $workspaceId, $date, $profiles): void {
            $this->db->execute('DELETE FROM backlink_history WHERE project_id = ? AND checked_on = ?', [$projectId, $date]);
            foreach ($profiles as $p) {
                $this->db->execute(
                    'INSERT INTO backlink_history (project_id, checked_on, domain, domain_rank, backlinks, referring_domains, spam_score) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$projectId, $date, mb_substr($p->domain, 0, 255), $p->rank, $p->backlinks, $p->referringDomains, $p->spamScore !== null ? max(0, min(255, $p->spamScore)) : null],
                );
            }
            $this->db->execute(
                'INSERT INTO backlink_settings (project_id, workspace_id, monthly, last_checked_on, updated_at) VALUES (?, ?, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE last_checked_on = VALUES(last_checked_on), updated_at = VALUES(updated_at)',
                [$projectId, $workspaceId, $date, $this->now()],
            );
        });
    }

    public function history(int $projectId, int $days): array
    {
        $dates = array_map(
            static fn(array $row): string => (string) $row['checked_on'],
            $this->db->fetchAll('SELECT DISTINCT checked_on FROM backlink_history WHERE project_id = ? ORDER BY checked_on DESC LIMIT ' . max(1, $days), [$projectId]),
        );
        if ($dates === []) {
            return [];
        }
        $byDate = array_fill_keys($dates, []);
        $rows = $this->db->fetchAll(
            'SELECT checked_on, domain, domain_rank, backlinks, referring_domains, spam_score FROM backlink_history
             WHERE project_id = ? AND checked_on IN (' . implode(', ', array_fill(0, count($dates), '?')) . ')',
            [$projectId, ...$dates],
        );
        $int = static fn(mixed $v): ?int => $v !== null ? (int) $v : null;
        foreach ($rows as $row) {
            $byDate[(string) $row['checked_on']][(string) $row['domain']] = new BacklinkProfile(
                (string) $row['domain'],
                $int($row['domain_rank']),
                $int($row['backlinks']),
                $int($row['referring_domains']),
                null,
                $int($row['spam_score']),
            );
        }

        return array_map(static fn(string $date): array => ['date' => $date, 'profiles' => $byDate[$date]], $dates);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
