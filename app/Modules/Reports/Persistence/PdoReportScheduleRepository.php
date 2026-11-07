<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Reports\ReportSchedule;
use SeoControlCenter\Modules\Reports\ReportScheduleRepository;

final readonly class PdoReportScheduleRepository implements ReportScheduleRepository
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function find(int $projectId): ?ReportSchedule
    {
        $row = $this->db->fetchOne('SELECT * FROM report_schedules WHERE project_id = ?', [$projectId]);

        return $row === null ? null : self::schedule($row);
    }
    public function sections(int $projectId): \SeoControlCenter\Modules\Reports\ReportSections
    {
        $value = $this->db->fetchValue('SELECT sections FROM project_report_sections WHERE project_id = ?', [$projectId]);
        return is_string($value) ? \SeoControlCenter\Modules\Reports\ReportSections::fromInput(json_decode($value, true, 512, JSON_THROW_ON_ERROR)) : new \SeoControlCenter\Modules\Reports\ReportSections();
    }
    public function saveSections(int $projectId, \SeoControlCenter\Modules\Reports\ReportSections $sections): void
    {
        $this->db->execute('INSERT INTO project_report_sections (project_id, sections) VALUES (?, ?) ON DUPLICATE KEY UPDATE sections = VALUES(sections)', [$projectId, json_encode($sections->enabled, JSON_THROW_ON_ERROR)]);
    }
    public function branding(int $workspaceId): \SeoControlCenter\Core\Branding\Branding
    {
        $row = $this->db->fetchOne('SELECT product_name, accent_color, report_footer FROM workspace_report_branding WHERE workspace_id = ?', [$workspaceId]);
        return new \SeoControlCenter\Core\Branding\Branding(is_string($row['product_name'] ?? null) ? $row['product_name'] : null, is_string($row['accent_color'] ?? null) ? $row['accent_color'] : null, is_string($row['report_footer'] ?? null) ? $row['report_footer'] : null);
    }
    public function saveBranding(int $workspaceId, \SeoControlCenter\Core\Branding\Branding $brand): void
    {
        $this->db->execute('INSERT INTO workspace_report_branding (workspace_id, product_name, accent_color, report_footer) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE product_name = VALUES(product_name), accent_color = VALUES(accent_color), report_footer = VALUES(report_footer)', [$workspaceId, $brand->productName, $brand->accentColor, $brand->reportFooter]);
    }
    public function logo(int $workspaceId): ?array
    {
        $row = $this->db->fetchOne('SELECT logo, logo_type FROM workspace_report_branding WHERE workspace_id = ?', [$workspaceId]);
        return is_string($row['logo'] ?? null) && is_string($row['logo_type'] ?? null) ? ['body' => $row['logo'], 'type' => $row['logo_type']] : null;
    }
    public function saveLogo(int $workspaceId, ?string $body, ?string $type): void
    {
        $this->db->execute('INSERT INTO workspace_report_branding (workspace_id, logo, logo_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE logo = VALUES(logo), logo_type = VALUES(logo_type)', [$workspaceId, $body, $type]);
    }

    public function save(int $projectId, int $workspaceId, string $frequency, array $recipients, bool $enabled, int $userId): void
    {
        $now = $this->now();
        $this->db->execute(
            'INSERT INTO report_schedules (project_id, workspace_id, frequency, recipients, enabled, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE frequency = VALUES(frequency), recipients = VALUES(recipients), enabled = VALUES(enabled),
                 updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$projectId, $workspaceId, $frequency, json_encode(array_values($recipients), JSON_THROW_ON_ERROR), $enabled ? 1 : 0, $userId, $now, $now],
        );
    }

    public function enabled(): array
    {
        return array_map(
            static fn(array $row): array => ['schedule' => self::schedule($row), 'projectPublicId' => (string) $row['public_id']],
            $this->db->fetchAll(
                'SELECT r.*, p.public_id FROM report_schedules r JOIN projects p ON p.id = r.project_id AND p.deleted_at IS NULL
                  WHERE r.enabled = 1 ORDER BY r.project_id',
            ),
        );
    }

    public function markSent(int $projectId, string $period): void
    {
        $this->db->execute('UPDATE report_schedules SET last_period = ?, last_sent_at = ? WHERE project_id = ?', [$period, $this->now(), $projectId]);
    }

    public function logDelivery(int $projectId, string $period, string $kind, int $recipients, int $failed, ?string $error): void
    {
        $this->db->execute(
            'INSERT INTO report_deliveries (project_id, period, kind, recipients, failed, error, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$projectId, $period, $kind === 'test' ? 'test' : 'scheduled', $recipients, $failed, $error !== null ? mb_substr($error, 0, 500) : null, $this->now()],
        );
    }

    public function deliveries(int $projectId, int $limit): array
    {
        return array_map(
            static fn(array $row): array => [
                'period' => (string) $row['period'],
                'kind' => (string) $row['kind'],
                'recipients' => (int) $row['recipients'],
                'failed' => (int) $row['failed'],
                'error' => $row['error'] !== null ? (string) $row['error'] : null,
                'created_at' => (string) $row['created_at'],
            ],
            $this->db->fetchAll('SELECT period, kind, recipients, failed, error, created_at FROM report_deliveries WHERE project_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$projectId]),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function schedule(array $row): ReportSchedule
    {
        $recipients = json_decode((string) $row['recipients'], true);

        return new ReportSchedule(
            (int) $row['project_id'],
            (int) $row['workspace_id'],
            (string) $row['frequency'],
            is_array($recipients) ? array_values(array_filter($recipients, is_string(...))) : [],
            (bool) $row['enabled'],
            $row['last_period'] !== null ? (string) $row['last_period'] : null,
            $row['last_sent_at'] !== null ? (string) $row['last_sent_at'] : null,
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
