<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\Opportunities\OutcomeMeasurementReader;
use SeoControlCenter\Domain\Opportunities\OutcomeMetrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;

final readonly class PdoOutcomeMeasurementReader implements OutcomeMeasurementReader
{
    public function __construct(private Connection $db, private SearchPerformanceReader $search) {}
    public function measure(int $projectId, string $subject, DateRange $range): ?OutcomeMetrics
    {
        if ($this->search->coveredDays($projectId, $range) !== $range->days()) {
            return null;
        }
        if (str_starts_with($subject, 'query:') || str_starts_with($subject, 'brand:')) {
            $sql = "SELECT SUM(s.clicks) AS clicks, SUM(s.impressions) AS impressions, SUM(s.position * s.impressions) / NULLIF(SUM(s.impressions), 0) AS position FROM search_console_queries s JOIN search_queries d ON d.id = s.query_id AND d.project_id = s.project_id WHERE s.project_id = ? AND s.date BETWEEN ? AND ? AND REGEXP_REPLACE(TRIM(d.query), '[[:space:]]+', ' ') = ?";
            $key = substr($subject, 6);
        } elseif (str_starts_with($subject, 'page:')) {
            $sql = "SELECT SUM(s.clicks) AS clicks, SUM(s.impressions) AS impressions, SUM(s.position * s.impressions) / NULLIF(SUM(s.impressions), 0) AS position FROM search_console_pages s JOIN pages d ON d.id = s.page_id AND d.project_id = s.project_id WHERE s.project_id = ? AND s.date BETWEEN ? AND ? AND BINARY TRIM(TRAILING '/' FROM SUBSTRING_INDEX(SUBSTRING_INDEX(d.url, '://', -1), '?', 1)) = BINARY ?";
            $key = rtrim(substr($subject, 5), '/');
        } else {
            return null;
        }
        $row = $this->db->fetchOne($sql, [$projectId, $range->fromString(), $range->toString(), $key]);
        return $row !== null && $row['clicks'] !== null && $row['impressions'] !== null ? new OutcomeMetrics((int) $row['clicks'], (int) $row['impressions'], $row['position'] !== null ? (float) $row['position'] : null) : null;
    }
}
