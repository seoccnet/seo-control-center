<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Alerts\Alert;
use SeoControlCenter\Modules\Alerts\AlertRepository;
use SeoControlCenter\Modules\Alerts\AlertType;
use SeoControlCenter\Modules\Alerts\NotificationSettings;

final readonly class PdoAlertRepository implements AlertRepository
{
    private const string COLUMNS = 'id, public_id, workspace_id, project_id, type, title, `lines`, url, created_at, sent_at';

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function record(int $workspaceId, ?int $projectId, AlertType $type, string $fingerprint, string $title, array $lines, string $url): ?Alert
    {
        $publicId = Uuid::v7($this->clock);
        $inserted = $this->db->execute(
            'INSERT IGNORE INTO alerts (public_id, workspace_id, project_id, type, fingerprint, title, `lines`, url, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$publicId, $workspaceId, $projectId, $type->value, mb_substr($fingerprint, 0, 191), mb_substr($title, 0, 255), json_encode(array_values($lines), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), mb_substr($url, 0, 255), $this->now()],
        );
        if ($inserted !== 1) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM alerts WHERE public_id = ?', [$publicId]);

        return $row !== null ? self::alert($row) : null;
    }

    public function find(int $alertId): ?Alert
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM alerts WHERE id = ?', [$alertId]);

        return $row !== null ? self::alert($row) : null;
    }

    public function markSent(int $alertId): void
    {
        $this->db->execute('UPDATE alerts SET sent_at = ? WHERE id = ?', [$this->now(), $alertId]);
    }

    public function recent(int $workspaceId, ?array $projectIds, string $since, int $limit): array
    {
        $params = [$workspaceId, $since];
        $scope = '';
        if ($projectIds !== null) {
            $scope = $projectIds === [] ? ' AND project_id IS NULL' : ' AND (project_id IS NULL OR project_id IN (' . implode(', ', array_fill(0, count($projectIds), '?')) . '))';
            array_push($params, ...$projectIds);
        }

        return array_map(self::alert(...), $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM alerts WHERE workspace_id = ? AND created_at >= ?' . $scope . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
            $params,
        ));
    }

    public function projects(): array
    {
        return array_map(
            static fn(array $row): array => ['projectId' => (int) $row['id'], 'workspaceId' => (int) $row['workspace_id'], 'publicId' => (string) $row['public_id'], 'name' => (string) $row['name'], 'domain' => (string) $row['primary_domain']],
            $this->db->fetchAll(
                'SELECT p.id, p.workspace_id, p.public_id, p.name, p.primary_domain FROM projects p JOIN workspaces w ON w.id = p.workspace_id
                 WHERE p.deleted_at IS NULL AND w.deleted_at IS NULL ORDER BY p.id',
            ),
        );
    }

    public function workspaces(): array
    {
        return array_map(static fn(array $row): int => (int) $row['id'], $this->db->fetchAll('SELECT id FROM workspaces WHERE deleted_at IS NULL ORDER BY id'));
    }

    public function settings(int $userId): NotificationSettings
    {
        $row = $this->db->fetchOne('SELECT alerts, muted_types, muted_projects, digest, digest_ai FROM notification_settings WHERE user_id = ?', [$userId]);
        if ($row === null) {
            return new NotificationSettings();
        }
        $types = json_decode((string) $row['muted_types'], true);
        $projects = json_decode((string) $row['muted_projects'], true);

        return new NotificationSettings(
            (bool) $row['alerts'],
            array_values(array_filter(array_map(static fn(mixed $t): ?AlertType => is_string($t) ? AlertType::tryFrom($t) : null, is_array($types) ? $types : []))),
            array_values(array_filter(is_array($projects) ? $projects : [], is_int(...))),
            (bool) $row['digest'],
            (bool) $row['digest_ai'],
        );
    }

    public function saveSettings(int $userId, NotificationSettings $settings): void
    {
        $this->db->execute(
            'INSERT INTO notification_settings (user_id, alerts, muted_types, muted_projects, digest, digest_ai, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE alerts = VALUES(alerts), muted_types = VALUES(muted_types), muted_projects = VALUES(muted_projects),
                 digest = VALUES(digest), digest_ai = VALUES(digest_ai), updated_at = VALUES(updated_at)',
            [
                $userId,
                $settings->alerts ? 1 : 0,
                json_encode(array_map(static fn(AlertType $t): string => $t->value, $settings->mutedTypes), JSON_THROW_ON_ERROR),
                json_encode(array_values($settings->mutedProjects), JSON_THROW_ON_ERROR),
                $settings->digest ? 1 : 0,
                $settings->digestAi ? 1 : 0,
                $this->now(),
            ],
        );
    }

    public function thresholds(int $projectId): \SeoControlCenter\Modules\Alerts\AlertThresholds
    {
        $row = $this->db->fetchOne('SELECT traffic_drop, minimum_clicks, ranking_cutoff, lost_domain_rank FROM project_alert_thresholds WHERE project_id = ?', [$projectId]);
        return $row === null ? new \SeoControlCenter\Modules\Alerts\AlertThresholds() : new \SeoControlCenter\Modules\Alerts\AlertThresholds((int) $row['traffic_drop'], (int) $row['minimum_clicks'], (int) $row['ranking_cutoff'], (int) $row['lost_domain_rank']);
    }

    public function saveThresholds(int $projectId, \SeoControlCenter\Modules\Alerts\AlertThresholds $thresholds): void
    {
        $this->db->execute('INSERT INTO project_alert_thresholds (project_id, traffic_drop, minimum_clicks, ranking_cutoff, lost_domain_rank) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE traffic_drop = VALUES(traffic_drop), minimum_clicks = VALUES(minimum_clicks), ranking_cutoff = VALUES(ranking_cutoff), lost_domain_rank = VALUES(lost_domain_rank)', [$projectId, $thresholds->trafficDrop, $thresholds->minimumClicks, $thresholds->rankingCutoff, $thresholds->lostDomainRank]);
    }

    public function claimDigest(int $userId, int $workspaceId, string $week): bool
    {
        return $this->db->execute('INSERT IGNORE INTO digest_log (user_id, workspace_id, week, created_at) VALUES (?, ?, ?, ?)', [$userId, $workspaceId, $week, $this->now()]) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function alert(array $row): Alert
    {
        $lines = json_decode((string) $row['lines'], true);

        return new Alert(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            $row['project_id'] !== null ? (int) $row['project_id'] : null,
            AlertType::tryFrom((string) $row['type']) ?? AlertType::RankingDrop,
            (string) $row['title'],
            array_values(array_filter(is_array($lines) ? $lines : [], is_string(...))),
            (string) $row['url'],
            (string) $row['created_at'],
            $row['sent_at'] !== null ? (string) $row['sent_at'] : null,
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
