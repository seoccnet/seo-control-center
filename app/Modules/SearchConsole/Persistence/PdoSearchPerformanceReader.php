<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\FilteredRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\RowFilter;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Aggregations over the imported tables. Every query filters by project and
 * date first, matching the (project_id, date, ...) primary keys. Average
 * position is weighted by impressions, as Search Console does.
 */
final readonly class PdoSearchPerformanceReader implements SearchPerformanceReader
{
    private const string METRICS = 'SUM(clicks) AS clicks, SUM(impressions) AS impressions,
        COALESCE(SUM(position * impressions) / NULLIF(SUM(impressions), 0), 0) AS position';

    public function __construct(private Connection $db) {}

    public function coveredDays(int $projectId, DateRange $range): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM (
            SELECT date FROM search_console_import_days WHERE project_id = ? AND date BETWEEN ? AND ?
            UNION
            SELECT date FROM search_console_daily WHERE project_id = ? AND date BETWEEN ? AND ? AND impressions = 0
        ) covered', [$projectId, $range->fromString(), $range->toString(), $projectId, $range->fromString(), $range->toString()]);
    }

    public function compared(int $projectId, PerformanceDimension $dimension, DateRange $range, int $limit): array
    {
        [$table, $key, $dictionary, $column] = match ($dimension) {
            PerformanceDimension::Query => ['queries', 'query_id', 'search_queries', 'query'],
            PerformanceDimension::Page => ['pages', 'page_id', 'pages', 'url'],
            default => throw new \InvalidArgumentException('Compare queries or pages.'),
        };
        $limit = max(1, min(500, $limit));
        $sql = sprintf('SELECT d.%s AS k, t.* FROM (
            SELECT a.*, COUNT(*) OVER () AS total, ROW_NUMBER() OVER (
                PARTITION BY SIGN(current_clicks - previous_clicks)
                ORDER BY ABS(current_clicks - previous_clicks) DESC, current_impressions DESC, id
            ) AS direction_rank FROM (
            SELECT %s AS id,
                SUM(CASE WHEN date >= ? THEN clicks ELSE 0 END) AS current_clicks,
                SUM(CASE WHEN date >= ? THEN impressions ELSE 0 END) AS current_impressions,
                SUM(CASE WHEN date >= ? THEN position * impressions ELSE 0 END) AS current_position_sum,
                SUM(CASE WHEN date < ? THEN clicks ELSE 0 END) AS previous_clicks,
                SUM(CASE WHEN date < ? THEN impressions ELSE 0 END) AS previous_impressions,
                SUM(CASE WHEN date < ? THEN position * impressions ELSE 0 END) AS previous_position_sum
            FROM search_console_%s WHERE project_id = ? AND date BETWEEN ? AND ?
            GROUP BY %s HAVING MIN(date) < ? AND MAX(date) >= ?
        ) a) t JOIN %s d ON d.id = t.id AND d.project_id = ?
        ORDER BY direction_rank, ABS(current_clicks - previous_clicks) DESC, current_impressions DESC, k LIMIT %d', $column, $key, $table, $key, $dictionary, $limit);
        $start = $range->fromString();
        $params = [$start, $start, $start, $start, $start, $start, $projectId, $range->previous()->fromString(), $range->toString(), $start, $start, $projectId];
        $rows = $this->db->fetchAll($sql, $params);
        $metrics = static fn(array $r, string $prefix): Metrics => new Metrics((int) $r[$prefix . '_clicks'], (int) $r[$prefix . '_impressions'], (float) $r[$prefix . '_impressions'] > 0 ? (float) $r[$prefix . '_position_sum'] / (float) $r[$prefix . '_impressions'] : 0.0);
        return ['total' => (int) ($rows[0]['total'] ?? 0), 'rows' => array_map(static fn(array $r): array => ['key' => (string) $r['k'], 'current' => $metrics($r, 'current'), 'previous' => $metrics($r, 'previous')], $rows)];
    }

    public function coveredDates(int $projectId, DateRange $range): array
    {
        return array_map(static fn(array $r): string => (string) $r['date'], $this->db->fetchAll('SELECT date FROM search_console_import_days WHERE project_id = ? AND date BETWEEN ? AND ?
            UNION
            SELECT date FROM search_console_daily WHERE project_id = ? AND date BETWEEN ? AND ? AND impressions = 0
            ORDER BY date', [$projectId, $range->fromString(), $range->toString(), $projectId, $range->fromString(), $range->toString()]));
    }

    public function dataSpan(int $projectId): ?array
    {
        $row = $this->db->fetchOne('SELECT MIN(date) AS first, MAX(date) AS last FROM search_console_daily WHERE project_id = ?', [$projectId]);
        if ($row === null || $row['first'] === null || $row['last'] === null) {
            return null;
        }

        return ['first' => (string) $row['first'], 'last' => (string) $row['last']];
    }

    public function totals(int $projectId, DateRange $range): Metrics
    {
        $row = $this->db->fetchOne(
            'SELECT ' . self::METRICS . ' FROM search_console_daily WHERE project_id = ? AND date BETWEEN ? AND ?',
            [$projectId, $range->fromString(), $range->toString()],
        );

        return $row === null ? Metrics::zero() : self::metrics($row);
    }

    public function daily(int $projectId, DateRange $range): array
    {
        $rows = $this->db->fetchAll(
            'SELECT date, clicks, impressions, position FROM search_console_daily
             WHERE project_id = ? AND date BETWEEN ? AND ? ORDER BY date',
            [$projectId, $range->fromString(), $range->toString()],
        );

        return array_map(static fn(array $r): DailyMetrics => new DailyMetrics((string) $r['date'], self::metrics($r)), $rows);
    }

    public function top(int $projectId, PerformanceDimension $dimension, DateRange $range, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        [$sql, $params] = match ($dimension) {
            PerformanceDimension::Query => $this->topKeyed('queries', 'query_id', 'search_queries', 'query', $projectId, $range, $limit),
            PerformanceDimension::Page => $this->topKeyed('pages', 'page_id', 'pages', 'url', $projectId, $range, $limit),
            PerformanceDimension::Device, PerformanceDimension::Country => [
                'SELECT value AS k, ' . self::METRICS . '
                    FROM search_console_dimensions
                    WHERE project_id = ? AND dimension = ? AND date BETWEEN ? AND ?
                    GROUP BY value',
                [$projectId, $dimension->value, $range->fromString(), $range->toString()],
            ],
        };

        $rows = array_map(static fn(array $r): DimensionRow => new DimensionRow((string) $r['k'], self::metrics($r)), $this->db->fetchAll($sql, $params));
        // The outer join does not keep the inner order; sort in PHP (at most $limit rows).
        usort($rows, static fn(DimensionRow $a, DimensionRow $b): int => [$b->metrics->clicks, $b->metrics->impressions, $a->key] <=> [$a->metrics->clicks, $a->metrics->impressions, $b->key]);

        return array_slice($rows, 0, $limit);
    }

    public function matching(int $projectId, PerformanceDimension $dimension, DateRange $range, RowFilter $filter, int $limit, int $offset = 0): array
    {
        [$table, $key, $dictionary, $column] = match ($dimension) {
            PerformanceDimension::Query => ['queries', 'query_id', 'search_queries', 'query'],
            PerformanceDimension::Page => ['pages', 'page_id', 'pages', 'url'],
            default => throw new \InvalidArgumentException('Filter queries or pages.'),
        };
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $start = $range->fromString();
        $first = $filter->compare ? $range->previous()->fromString() : $start;

        // Metric bounds on the current period (HAVING over the aggregates).
        $having = [];
        $havingParams = [];
        $ctr = 'c_clicks / NULLIF(c_impressions, 0)';
        $position = 'c_position_sum / NULLIF(c_impressions, 0)';
        if ($filter->minImpressions > 0 || !$filter->compare) {
            $having[] = 'c_impressions >= ?';
            $havingParams[] = max($filter->compare ? 0 : 1, $filter->minImpressions);
        }
        if ($filter->minClicks > 0) {
            $having[] = 'c_clicks >= ?';
            $havingParams[] = $filter->minClicks;
        }
        foreach ([[$ctr, '>=', $filter->minCtr], [$ctr, '<=', $filter->maxCtr], [$position, '>=', $filter->minPosition], [$position, '<=', $filter->maxPosition]] as [$expression, $operator, $bound]) {
            if ($bound !== null) {
                $having[] = $expression . ' ' . $operator . ' ?';
                $havingParams[] = $bound;
            }
        }
        if ($filter->presence === 'new') {
            $having[] = 'c_impressions > 0 AND p_impressions = 0';
        } elseif ($filter->presence === 'lost') {
            $having[] = 'c_impressions = 0 AND p_impressions > 0';
        }

        // Text filters on the dictionary value; "!" escapes LIKE wildcards in any SQL mode.
        $where = [];
        $whereParams = [];
        $like = static fn(string $term): string => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
        if ($filter->contains !== []) {
            $where[] = '(' . implode($filter->containsAll ? ' AND ' : ' OR ', array_fill(0, count($filter->contains), 'd.' . $column . " LIKE ? ESCAPE '!'")) . ')';
            array_push($whereParams, ...array_map($like, $filter->contains));
        }
        foreach ($filter->exclude as $term) {
            $where[] = 'd.' . $column . " NOT LIKE ? ESCAPE '!'";
            $whereParams[] = $like($term);
        }
        if ($filter->minWords > 1) {
            $where[] = 'CHAR_LENGTH(TRIM(d.' . $column . ')) - CHAR_LENGTH(REPLACE(TRIM(d.' . $column . "), ' ', '')) + 1 >= ?";
            $whereParams[] = $filter->minWords;
        }

        $order = match ($filter->sort) {
            'clicks' => 'c_clicks',
            'impressions' => 'c_impressions',
            'ctr' => 'COALESCE(' . $ctr . ', 0)',
            'position' => 'COALESCE(' . $position . ', 1000)',
            'clicks_change' => '(CAST(c_clicks AS SIGNED) - CAST(p_clicks AS SIGNED))',
            'impressions_change' => '(CAST(c_impressions AS SIGNED) - CAST(p_impressions AS SIGNED))',
            'position_change' => 'CASE WHEN c_impressions > 0 AND p_impressions > 0 THEN c_position_sum / c_impressions - p_position_sum / p_impressions ELSE 0 END',
            default => 'c_clicks',
        };

        $sql = sprintf(
            'SELECT d.%1$s AS k, t.*, COUNT(*) OVER () AS total, SUM(t.c_clicks) OVER () AS total_clicks,
                SUM(t.c_impressions) OVER () AS total_impressions, SUM(t.c_position_sum) OVER () AS total_position_sum FROM (
                SELECT %2$s AS id,
                    SUM(CASE WHEN date >= ? THEN clicks ELSE 0 END) AS c_clicks,
                    SUM(CASE WHEN date >= ? THEN impressions ELSE 0 END) AS c_impressions,
                    SUM(CASE WHEN date >= ? THEN position * impressions ELSE 0 END) AS c_position_sum,
                    SUM(CASE WHEN date < ? THEN clicks ELSE 0 END) AS p_clicks,
                    SUM(CASE WHEN date < ? THEN impressions ELSE 0 END) AS p_impressions,
                    SUM(CASE WHEN date < ? THEN position * impressions ELSE 0 END) AS p_position_sum
                FROM search_console_%3$s WHERE project_id = ? AND date BETWEEN ? AND ?
                GROUP BY %2$s%4$s
            ) t JOIN %5$s d ON d.id = t.id AND d.project_id = ?%6$s
            ORDER BY %7$s %8$s, c_impressions DESC, k
            LIMIT %9$d OFFSET %10$d',
            $column,
            $key,
            $table,
            $having === [] ? '' : ' HAVING ' . implode(' AND ', $having),
            $dictionary,
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            $order,
            $filter->descending ? 'DESC' : 'ASC',
            $limit,
            $offset,
        );
        $params = [$start, $start, $start, $start, $start, $start, $projectId, $first, $range->toString(), ...$havingParams, $projectId, ...$whereParams];
        $rows = $this->db->fetchAll($sql, $params);
        $metrics = static fn(array $r, string $p): Metrics => new Metrics((int) $r[$p . '_clicks'], (int) $r[$p . '_impressions'], (float) $r[$p . '_impressions'] > 0 ? round((float) $r[$p . '_position_sum'] / (float) $r[$p . '_impressions'], 2) : 0.0);
        if ($rows === [] && $offset > 0) {
            $first = $this->matching($projectId, $dimension, $range, $filter, 1);

            return ['total' => $first['total'], 'totals' => $first['totals'], 'rows' => []];
        }

        return [
            'total' => (int) ($rows[0]['total'] ?? 0),
            'totals' => $rows === [] ? Metrics::zero() : $metrics($rows[0], 'total'),
            'rows' => array_map(static fn(array $r): FilteredRow => new FilteredRow((string) $r['k'], $metrics($r, 'c'), $filter->compare ? $metrics($r, 'p') : null), $rows),
        ];
    }

    public function queryTotals(int $projectId, string $query, DateRange $range): ?Metrics
    {
        $row = $this->db->fetchOne(
            'SELECT ' . self::METRICS . ', COUNT(*) AS days FROM search_console_queries
             WHERE project_id = ? AND query_id = (SELECT id FROM search_queries WHERE project_id = ? AND query_hash = UNHEX(?))
               AND date BETWEEN ? AND ?',
            [$projectId, $projectId, hash('sha256', $query), $range->fromString(), $range->toString()],
        );

        return $row === null || (int) $row['days'] === 0 ? null : self::metrics($row);
    }

    /**
     * Top rows of the query or page table. Whole months with a valid rollup
     * are read from the monthly table, everything else from the daily rows
     * (in primary key order, which holds every column).
     *
     * @return array{string, list<int|string>}
     */
    private function topKeyed(string $table, string $key, string $dictionary, string $column, int $projectId, DateRange $range, int $limit): array
    {
        $rolledUp = $this->rolledUpMonths($projectId, $range);
        $daily = 'SELECT %1$s AS id, clicks, impressions, position * impressions AS ps FROM search_console_%2$s FORCE INDEX (PRIMARY) WHERE project_id = ? AND date BETWEEN ? AND ?';

        $parts = [];
        $params = [];
        foreach ($this->daySpansOutside($range, $rolledUp) as [$from, $to]) {
            $parts[] = sprintf($daily, $key, $table);
            array_push($params, $projectId, $from, $to);
        }
        if ($rolledUp !== []) {
            $parts[] = sprintf(
                'SELECT %s AS id, clicks, impressions, position_sum AS ps FROM search_console_%s_monthly WHERE project_id = ? AND month IN (%s)',
                $key,
                $table,
                implode(', ', array_fill(0, count($rolledUp), '?')),
            );
            array_push($params, $projectId, ...$rolledUp);
        }

        return [
            sprintf(
                'SELECT d.%s AS k, t.clicks, t.impressions, t.position FROM (
                    SELECT id, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                           COALESCE(SUM(ps) / NULLIF(SUM(impressions), 0), 0) AS position
                      FROM (%s) u GROUP BY id
                     ORDER BY clicks DESC, impressions DESC LIMIT %d
                ) t JOIN %s d ON d.id = t.id',
                $column,
                implode(' UNION ALL ', $parts),
                $limit,
                $dictionary,
            ),
            $params,
        ];
    }

    /**
     * Months lying completely inside the range whose rollup is valid.
     *
     * @return list<string> first days of the months, ascending
     */
    private function rolledUpMonths(int $projectId, DateRange $range): array
    {
        $candidates = [];
        for ($month = $range->from->modify('first day of this month'); $month <= $range->to; $month = $month->modify('first day of next month')) {
            if ($month >= $range->from && $month->modify('last day of this month') <= $range->to) {
                $candidates[] = $month->format('Y-m-d');
            }
        }
        if ($candidates === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT month FROM search_console_rollup_months WHERE project_id = ? AND month BETWEEN ? AND ? ORDER BY month',
            [$projectId, $candidates[0], $candidates[count($candidates) - 1]],
        );

        return array_map(static fn(array $r): string => (string) $r['month'], $rows);
    }

    /**
     * The parts of the range not covered by the rolled-up months, as contiguous day spans.
     *
     * @param list<string> $rolledUp
     * @return list<array{string, string}>
     */
    private function daySpansOutside(DateRange $range, array $rolledUp): array
    {
        $spans = [];
        $cursor = $range->from;
        foreach ($rolledUp as $month) {
            $start = new \DateTimeImmutable($month, $range->from->getTimezone());
            if ($cursor < $start) {
                $spans[] = [$cursor->format('Y-m-d'), $start->modify('-1 day')->format('Y-m-d')];
            }
            $cursor = $start->modify('first day of next month');
        }
        if ($cursor <= $range->to) {
            $spans[] = [$cursor->format('Y-m-d'), $range->toString()];
        }

        return $spans;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function metrics(array $row): Metrics
    {
        return new Metrics(
            (int) ($row['clicks'] ?? 0),
            (int) ($row['impressions'] ?? 0),
            round((float) ($row['position'] ?? 0), 2),
        );
    }
}
