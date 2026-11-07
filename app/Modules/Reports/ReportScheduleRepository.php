<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

/**
 * Report schedules and the delivery log, scoped by project.
 */
interface ReportScheduleRepository
{
    public function find(int $projectId): ?ReportSchedule;
    public function sections(int $projectId): ReportSections;
    public function saveSections(int $projectId, ReportSections $sections): void;
    public function branding(int $workspaceId): \SeoControlCenter\Core\Branding\Branding;
    public function saveBranding(int $workspaceId, \SeoControlCenter\Core\Branding\Branding $brand): void;
    /** @return array{body: string, type: string}|null */
    public function logo(int $workspaceId): ?array;
    public function saveLogo(int $workspaceId, ?string $body, ?string $type): void;

    /**
     * @param list<string> $recipients
     */
    public function save(int $projectId, int $workspaceId, string $frequency, array $recipients, bool $enabled, int $userId): void;

    /**
     * Enabled schedules of projects that still exist, with the project's public ID.
     *
     * @return list<array{schedule: ReportSchedule, projectPublicId: string}>
     */
    public function enabled(): array;

    public function markSent(int $projectId, string $period): void;

    public function logDelivery(int $projectId, string $period, string $kind, int $recipients, int $failed, ?string $error): void;

    /**
     * @return list<array{period: string, kind: string, recipients: int, failed: int, error: ?string, created_at: string}>
     */
    public function deliveries(int $projectId, int $limit): array;
}
