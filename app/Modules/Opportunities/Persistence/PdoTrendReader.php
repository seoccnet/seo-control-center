<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\Opportunities\TrendReader;
use SeoControlCenter\Domain\Opportunities\TrendSnapshot;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\Shared\DateRange;

final readonly class PdoTrendReader implements TrendReader
{
    public function __construct(private Connection $db) {}

    public function snapshot(int $projectId, DateRange $range): TrendSnapshot
    {
        $queryRows = $pageRows = [];
        $previous = $older = $previousQueries = [];
        $queryCount = $pageCount = 0;
        $pageIds = [];
        foreach ([['query', 'queries', 'query_id', 'search_queries', 'query'], ['page', 'pages', 'page_id', 'pages', 'url']] as [$kind, $table, $id, $dictionary, $key]) {
            $rows = $this->db->fetchAll(sprintf('SELECT d.%s AS subject, t.* FROM (
                SELECT %s AS subject_id, COUNT(*) OVER () AS total,
                    SUM(CASE WHEN date >= ? THEN clicks ELSE 0 END) AS clicks,
                    SUM(CASE WHEN date >= ? THEN impressions ELSE 0 END) AS impressions,
                    SUM(CASE WHEN date >= ? THEN position * impressions ELSE 0 END) AS position_sum,
                    SUM(CASE WHEN date >= ? THEN 1 ELSE 0 END) AS current_days,
                    SUM(CASE WHEN date >= ? AND date < ? THEN clicks ELSE 0 END) AS previous_clicks,
                    SUM(CASE WHEN date >= ? AND date < ? THEN impressions ELSE 0 END) AS previous_impressions,
                    SUM(CASE WHEN date >= ? AND date < ? THEN position * impressions ELSE 0 END) AS previous_position_sum,
                    SUM(CASE WHEN date >= ? AND date < ? THEN 1 ELSE 0 END) AS previous_days,
                    SUM(CASE WHEN date < ? THEN clicks ELSE 0 END) AS older_clicks,
                    SUM(CASE WHEN date < ? THEN impressions ELSE 0 END) AS older_impressions,
                    SUM(CASE WHEN date < ? THEN position * impressions ELSE 0 END) AS older_position_sum,
                    SUM(CASE WHEN date < ? THEN 1 ELSE 0 END) AS older_days
                FROM search_console_%s WHERE project_id = ? AND date BETWEEN ? AND ?
                GROUP BY %s HAVING current_days > 0 ORDER BY impressions DESC, subject_id LIMIT 500
            ) t JOIN %s d ON d.id = t.subject_id AND d.project_id = ? ORDER BY t.impressions DESC, t.subject_id', $key, $id, $table, $id, $dictionary), [$range->fromString(), $range->fromString(), $range->fromString(), $range->fromString(), $range->previous()->fromString(), $range->fromString(), $range->previous()->fromString(), $range->fromString(), $range->previous()->fromString(), $range->fromString(), $range->previous()->fromString(), $range->fromString(), $range->previous()->fromString(), $range->previous()->fromString(), $range->previous()->fromString(), $range->previous()->fromString(), $projectId, $range->previous()->previous()->fromString(), $range->toString(), $projectId]);
            foreach ($rows as $r) {
                $metrics = self::metrics($r, '');
                if ($kind === 'query') {
                    $queryRows[] = new DimensionRow((string) $r['subject'], $metrics);
                    $queryCount = (int) $r['total'];
                    if ((int) $r['previous_days'] > 0) {
                        $previousQueries[(string) $r['subject']] = self::metrics($r, 'previous_');
                    }
                } else {
                    $url = (string) $r['subject'];
                    $pageRows[] = new DimensionRow($url, $metrics);
                    $pageCount = (int) $r['total'];
                    $pageIds[(int) $r['subject_id']] = $url;
                    if ((int) $r['previous_days'] > 0) {
                        $previous[$url] = self::metrics($r, 'previous_');
                    }
                    if ((int) $r['older_days'] > 0) {
                        $older[$url] = self::metrics($r, 'older_');
                    }
                }
            }
        }
        $lastMonth = $range->to->modify('first day of this month')->modify('-1 month');
        $month = $lastMonth->modify('-1 year')->format('Y-m-d');
        $priorMonth = $lastMonth->modify('-1 year')->modify('-1 month')->format('Y-m-d');
        $marks = array_column($this->db->fetchAll('SELECT month FROM search_console_rollup_months WHERE project_id = ? AND month BETWEEN ? AND ?', [$projectId, $priorMonth, $lastMonth->format('Y-m-d')]), 'month');
        $complete = true;
        for ($i = 0; $i < 13; ++$i) {
            $complete = $complete && in_array($lastMonth->modify('-' . $i . ' months')->format('Y-m-d'), $marks, true);
        }
        $seasonality = [];
        if ($complete && in_array($priorMonth, $marks, true) && $pageIds !== []) {
            $rows = $this->db->fetchAll('SELECT now.page_id, now.clicks AS current_clicks, old.clicks AS previous_clicks
                FROM search_console_pages_monthly now JOIN search_console_pages_monthly old ON old.project_id = now.project_id AND old.page_id = now.page_id AND old.month = ?
                WHERE now.project_id = ? AND now.month = ? AND old.clicks > 0 AND now.page_id IN (' . implode(', ', array_fill(0, count($pageIds), '?')) . ')', [$priorMonth, $projectId, $month, ...array_keys($pageIds)]);
            foreach ($rows as $r) {
                $seasonality[$pageIds[(int) $r['page_id']]] = (float) $r['current_clicks'] / (int) $r['previous_clicks'];
            }
        }
        $projectName = $this->db->fetchValue('SELECT name FROM projects WHERE id = ? AND deleted_at IS NULL', [$projectId]);
        return new TrendSnapshot($queryRows, $pageRows, $previous, $older, $seasonality, $queryCount, $pageCount, is_string($projectName) ? $projectName : null, $previousQueries);
    }

    /** @param array<string, mixed> $row */
    private static function metrics(array $row, string $prefix): Metrics
    {
        $impressions = (int) $row[$prefix . 'impressions'];
        return new Metrics((int) $row[$prefix . 'clicks'], $impressions, $impressions > 0 ? (float) $row[$prefix . 'position_sum'] / $impressions : 0.0);
    }
}
