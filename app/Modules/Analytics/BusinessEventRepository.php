<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics;

use SeoControlCenter\Domain\Analytics\BusinessEvent;
use SeoControlCenter\Domain\Analytics\Ga4EventReport;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Business events, their imported daily GA4 counts and the owner's test
 * checks (ADR-042). Always scoped by project.
 */
interface BusinessEventRepository
{
    /** @return list<BusinessEvent> oldest first */
    public function events(int $projectId): array;

    public function add(int $workspaceId, int $projectId, string $kind, string $eventName, string $label, ?int $userId): BusinessEvent;

    /** Removes the definition and its imported counts. */
    public function remove(int $projectId, string $publicId): bool;

    /** Replaces the stored counts of the report's dates and names and marks the import successful. */
    public function saveEventReport(int $projectId, DateRange $range, Ga4EventReport $report): void;

    public function recordEventFailure(int $projectId, string $error): void;

    /** @return array{from: ?string, to: ?string, timezone: ?string, limited: bool, thresholded: bool, fetched_at: ?string, error: ?string, error_at: ?string}|null */
    public function eventImport(int $projectId): ?array;

    /** @return array<string, array{organic: int, total: int, days: int}> per event name, imported days only */
    public function counts(int $projectId, DateRange $range): array;

    public function addCheck(int $workspaceId, int $projectId, string $note, ?int $userId): string;

    /** @return array{id: string, project_id: int, workspace_id: int, status: string, note: string, result: ?array<string, int>, error: ?string, requested_at: string, finished_at: ?string}|null */
    public function check(string $publicId): ?array;

    /** @param array<string, int>|null $result */
    public function finishCheck(string $publicId, ?array $result, ?string $error): void;

    /** @return list<array{id: string, project_id: int, workspace_id: int, status: string, note: string, result: ?array<string, int>, error: ?string, requested_at: string, finished_at: ?string}> newest first */
    public function checks(int $projectId, int $limit = 5): array;
}
