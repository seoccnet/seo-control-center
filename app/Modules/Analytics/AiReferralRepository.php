<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics;

use SeoControlCenter\Domain\Analytics\Ga4AiReferralReport;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * GA4 sessions referred by AI assistants (0.43.0).
 */
interface AiReferralRepository
{
    /** Replaces the project's rows inside the range and records the import window. */
    public function save(int $projectId, DateRange $range, Ga4AiReferralReport $report): void;

    public function recordFailure(int $projectId, string $error): void;

    /** @return array{from: ?string, to: ?string, currency: ?string, limited: bool, fetched_at: ?string, error: ?string, error_at: ?string}|null */
    public function lastImport(int $projectId): ?array;

    /**
     * Totals per session source in the range, most sessions first.
     *
     * @return list<array{source: string, sessions: int, key_events: float, revenue: float}>
     */
    public function bySource(int $projectId, DateRange $range): array;
}
