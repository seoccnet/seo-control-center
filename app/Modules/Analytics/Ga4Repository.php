<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics;

use SeoControlCenter\Domain\Analytics\Ga4Credentials;
use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\Shared\DateRange;

interface Ga4Repository
{
    public function hasCredentials(int $workspaceId): bool;
    public function credentials(int $workspaceId): ?Ga4Credentials;
    public function saveCredentials(int $workspaceId, #[\SensitiveParameter] Ga4Credentials $credentials): void;
    public function deleteCredentials(int $workspaceId): void;
    public function link(int $projectId, string $propertyId): void;
    public function propertyId(int $projectId): ?string;
    public function saveReport(int $projectId, DateRange $range, Ga4Report $report, string $propertyId): void;
    /** @return array{from: string, to: string, currency: string, timezone: string, limited: bool, thresholded: bool, fetched_at: string}|null */
    public function metadata(int $projectId, ?DateRange $range = null): ?array;
    /** Number of imported dates matching the latest currency/timezone; includes successful zero-row dates. */
    public function coveredDays(int $projectId, DateRange $range): int;
    /** @param list<string> $urls Optional exact URL set for bounded comparisons.
     * @return list<array{url: string, sessions: int, conversions: float, revenue: float}>
     */
    public function landingPages(int $projectId, DateRange $range, int $limit = 100, ?string $url = null, array $urls = [], int $offset = 0): array;
    /** @return array{sessions: int, conversions: float, revenue: float, pages: int} */
    public function totals(int $projectId, DateRange $range, ?string $url = null): array;
    /** Successfully imported matching-currency dates, including zero-row days.
     * @return list<array{date: string, sessions: int, conversions: float, revenue: float}>
     */
    public function daily(int $projectId, DateRange $range, ?string $url = null): array;
    /** @return list<array{project_id: int, workspace_id: int, project_public_id: string}> */
    public function linkedProjects(): array;
}
