<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\QueryPageRow;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\SearchConsole\SyncState;

/**
 * MariaDB/MySQL storage for imported performance data. Rows are written in
 * multi-row batches so a 5,000-row day needs a handful of statements.
 */
final readonly class PdoSearchPerformanceStore implements SearchPerformanceStore
{
    private const int BATCH = 500;
    private const int QUERY_MAX_LENGTH = 500;
    private const int URL_MAX_LENGTH = 2048;

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function syncState(int $projectId): ?SyncState
    {
        $row = $this->db->fetchOne(
            'SELECT project_id, site_url, status, totals_refreshed_at, last_success_at, last_error FROM search_console_sync WHERE project_id = ?',
            [$projectId],
        );
        if ($row === null) {
            return null;
        }

        return new SyncState(
            (int) $row['project_id'],
            (string) $row['site_url'],
            (string) $row['status'],
            $row['totals_refreshed_at'] !== null ? (string) $row['totals_refreshed_at'] : null,
            $row['last_success_at'] !== null ? (string) $row['last_success_at'] : null,
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
        );
    }

    public function saveSyncState(SyncState $state): void
    {
        $this->db->execute(
            'INSERT INTO search_console_sync (project_id, site_url, status, totals_refreshed_at, last_success_at, last_error, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE site_url = VALUES(site_url), status = VALUES(status), totals_refreshed_at = VALUES(totals_refreshed_at),
                 last_success_at = VALUES(last_success_at), last_error = VALUES(last_error), updated_at = VALUES(updated_at)',
            [$state->projectId, $state->siteUrl, $state->status, $state->totalsRefreshedAt, $state->lastSuccessAt, $state->lastError, $this->now()],
        );
    }

    public function purge(int $projectId): void
    {
        $this->db->transaction(function () use ($projectId): void {
            foreach (['search_console_query_pages', 'search_console_query_page_days', 'search_console_queries', 'search_console_pages', 'search_console_queries_monthly', 'search_console_pages_monthly', 'search_console_rollup_months', 'search_console_dimensions', 'search_console_daily', 'search_console_import_days', 'search_console_sync', 'search_queries', 'search_console_fresh_daily', 'search_console_search_types', 'search_console_sitemaps'] as $table) {
                $this->db->execute(sprintf('DELETE FROM %s WHERE project_id = ?', $table), [$projectId]);
            }
        });
    }

    public function upsertDailyTotals(int $projectId, array $days): void
    {
        foreach (array_chunk($days, self::BATCH) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $day) {
                $values[] = '(?, ?, ?, ?, ?)';
                array_push($params, $projectId, $day->date, $day->metrics->clicks, $day->metrics->impressions, $day->metrics->position);
            }
            $this->db->execute(
                'INSERT INTO search_console_daily (project_id, date, clicks, impressions, position) VALUES ' . implode(', ', $values) . '
                 ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions), position = VALUES(position)',
                $params,
            );
        }
    }

    public function pendingDays(int $projectId, int $limit): array
    {
        $rows = $this->db->fetchAll(
            sprintf(
                'SELECT d.date FROM search_console_daily d
                 LEFT JOIN search_console_import_days i ON i.project_id = d.project_id AND i.date = d.date
                 WHERE d.project_id = ? AND d.impressions > 0 AND i.date IS NULL
                 ORDER BY d.date DESC LIMIT %d',
                max(1, $limit),
            ),
            [$projectId],
        );

        return array_map(static fn(array $r): string => (string) $r['date'], $rows);
    }

    public function countPendingDays(int $projectId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM search_console_daily d
             LEFT JOIN search_console_import_days i ON i.project_id = d.project_id AND i.date = d.date
             WHERE d.project_id = ? AND d.impressions > 0 AND i.date IS NULL',
            [$projectId],
        );
    }

    public function replaceDay(int $projectId, string $date, array $rows): void
    {
        $queries = $rows[PerformanceDimension::Query->value] ?? [];
        $pages = $rows[PerformanceDimension::Page->value] ?? [];

        // Dictionary entries are created outside the day transaction: they are
        // harmless if the transaction below fails and keep it short.
        $queryIds = $this->dictionary('search_queries', 'query', $projectId, $queries, self::QUERY_MAX_LENGTH, false);
        $pageIds = $this->dictionary('pages', 'url', $projectId, $pages, self::URL_MAX_LENGTH, true);

        $this->db->transaction(function () use ($projectId, $date, $rows, $queries, $pages, $queryIds, $pageIds): void {
            foreach (['search_console_queries', 'search_console_pages', 'search_console_dimensions'] as $table) {
                $this->db->execute(sprintf('DELETE FROM %s WHERE project_id = ? AND date = ?', $table), [$projectId, $date]);
            }
            // The month's rollup no longer matches; readers use the daily rows until it is rebuilt.
            $this->db->execute('DELETE FROM search_console_rollup_months WHERE project_id = ? AND month = ?', [$projectId, substr($date, 0, 8) . '01']);
            $this->insertKeyed('search_console_queries', 'query_id', $projectId, $date, $queries, $queryIds);
            $this->insertKeyed('search_console_pages', 'page_id', $projectId, $date, $pages, $pageIds);

            $dimensionRows = [];
            foreach ([PerformanceDimension::Device, PerformanceDimension::Country] as $dimension) {
                foreach ($rows[$dimension->value] ?? [] as $row) {
                    $value = strtolower(substr($row->key, 0, 32));
                    // The key is part of the primary key; merge duplicates defensively.
                    $dimensionRows[$dimension->value . "\0" . $value] = [$dimension->value, $value, $row];
                }
            }
            foreach (array_chunk(array_values($dimensionRows), self::BATCH) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as [$dimension, $value, $row]) {
                    $values[] = '(?, ?, ?, ?, ?, ?, ?)';
                    array_push($params, $projectId, $date, $dimension, $value, $row->metrics->clicks, $row->metrics->impressions, $row->metrics->position);
                }
                $this->db->execute(
                    'INSERT INTO search_console_dimensions (project_id, date, dimension, value, clicks, impressions, position) VALUES ' . implode(', ', $values),
                    $params,
                );
            }

            $this->db->execute(
                'INSERT INTO search_console_import_days (project_id, date, imported_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE imported_at = VALUES(imported_at)',
                [$projectId, $date, $this->now()],
            );
        });
    }

    public function pendingQueryPageDays(int $projectId, int $limit): array
    {
        return array_map(static fn(array $r): string => (string) $r['date'], $this->db->fetchAll(
            'SELECT d.date FROM search_console_import_days d
             LEFT JOIN search_console_query_page_days q ON q.project_id = d.project_id AND q.date = d.date
             WHERE d.project_id = ? AND q.date IS NULL
               AND d.date >= (SELECT DATE_SUB(MAX(date), INTERVAL 27 DAY) FROM search_console_daily WHERE project_id = ?)
             ORDER BY d.date DESC LIMIT ' . max(1, min(28, $limit)),
            [$projectId, $projectId],
        ));
    }

    public function replaceQueryPages(int $projectId, string $date, array $rows): void
    {
        $queries = array_map(static fn(QueryPageRow $r): DimensionRow => new DimensionRow($r->query, $r->metrics), $rows);
        $pages = array_map(static fn(QueryPageRow $r): DimensionRow => new DimensionRow($r->url, $r->metrics), $rows);
        $queryIds = $this->dictionary('search_queries', 'query', $projectId, $queries, self::QUERY_MAX_LENGTH, false);
        $pageIds = $this->dictionary('pages', 'url', $projectId, $pages, self::URL_MAX_LENGTH, true);
        $this->db->transaction(function () use ($projectId, $date, $rows, $queryIds, $pageIds): void {
            $this->db->execute('DELETE FROM search_console_query_pages WHERE project_id = ? AND date = ?', [$projectId, $date]);
            $merged = [];
            foreach ($rows as $row) {
                $merged[hash('sha256', $row->query) . hash('sha256', $row->url)] = $row;
            }
            foreach (array_chunk(array_values($merged), self::BATCH) as $chunk) {
                $params = [];
                foreach ($chunk as $row) {
                    array_push($params, $projectId, $date, $queryIds[hash('sha256', $row->query)], $pageIds[hash('sha256', $row->url)], $row->metrics->clicks, $row->metrics->impressions, $row->metrics->position);
                }
                $this->db->execute('INSERT INTO search_console_query_pages (project_id, date, query_id, page_id, clicks, impressions, position) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?)')), $params);
            }
            $this->db->execute('INSERT INTO search_console_query_page_days (project_id, date, imported_at) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE imported_at = VALUES(imported_at)', [$projectId, $date, $this->now()]);
        });
    }

    public function refreshRollups(int $projectId, \Closure $continue): int
    {
        // Months with imported days, no valid rollup and no day still waiting for import.
        $months = $this->db->fetchAll(
            "SELECT m.month FROM (
                 SELECT DISTINCT CAST(DATE_FORMAT(date, '%Y-%m-01') AS DATE) AS month FROM search_console_import_days WHERE project_id = ?
             ) m
             WHERE NOT EXISTS (SELECT 1 FROM search_console_rollup_months r WHERE r.project_id = ? AND r.month = m.month)
               AND NOT EXISTS (
                 SELECT 1 FROM search_console_daily d
                 LEFT JOIN search_console_import_days i ON i.project_id = d.project_id AND i.date = d.date
                 WHERE d.project_id = ? AND d.impressions > 0 AND i.date IS NULL AND d.date BETWEEN m.month AND LAST_DAY(m.month)
               )
             ORDER BY m.month DESC",
            [$projectId, $projectId, $projectId],
        );

        $rebuilt = 0;
        foreach ($months as $row) {
            if (!$continue()) {
                break;
            }
            $month = (string) $row['month'];
            $this->db->transaction(function () use ($projectId, $month): void {
                foreach (['queries' => 'query_id', 'pages' => 'page_id'] as $table => $key) {
                    $this->db->execute(sprintf('DELETE FROM search_console_%s_monthly WHERE project_id = ? AND month = ?', $table), [$projectId, $month]);
                    // FORCE INDEX: the primary key range is read sequentially and already holds every column.
                    $this->db->execute(
                        sprintf(
                            'INSERT INTO search_console_%1$s_monthly (project_id, month, %2$s, clicks, impressions, position_sum)
                             SELECT project_id, ?, %2$s, SUM(clicks), SUM(impressions), SUM(position * impressions)
                               FROM search_console_%1$s FORCE INDEX (PRIMARY)
                              WHERE project_id = ? AND date BETWEEN ? AND LAST_DAY(?)
                              GROUP BY project_id, %2$s',
                            $table,
                            $key,
                        ),
                        [$month, $projectId, $month, $month],
                    );
                }
                $this->db->execute(
                    'INSERT INTO search_console_rollup_months (project_id, month, refreshed_at) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE refreshed_at = VALUES(refreshed_at)',
                    [$projectId, $month, $this->now()],
                );
            });
            ++$rebuilt;
        }

        return $rebuilt;
    }

    /**
     * Ensures dictionary entries exist and returns hash => id.
     *
     * @param list<DimensionRow> $rows
     * @return array<string, int> hex SHA-256 of the full value => id
     */
    private function dictionary(string $table, string $column, int $projectId, array $rows, int $maxLength, bool $withFirstSeen): array
    {
        $values = [];
        foreach ($rows as $row) {
            $values[hash('sha256', $row->key)] = $row->key;
        }
        $ids = [];
        foreach (array_chunk($values, self::BATCH, true) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $hash => $value) {
                // Hashes travel as hex and are converted in SQL, so binary bytes never
                // pass through the utf8mb4 connection character set.
                $placeholders[] = $withFirstSeen ? '(?, UNHEX(?), ?, ?)' : '(?, UNHEX(?), ?)';
                array_push($params, $projectId, (string) $hash, mb_substr($value, 0, $maxLength));
                if ($withFirstSeen) {
                    $params[] = $this->now();
                }
            }
            $columns = $withFirstSeen ? "project_id, {$column}_hash, {$column}, first_seen_at" : "project_id, {$column}_hash, {$column}";
            $this->db->execute(sprintf('INSERT IGNORE INTO %s (%s) VALUES %s', $table, $columns, implode(', ', $placeholders)), $params);

            $hashes = array_map('strval', array_keys($chunk));
            $found = $this->db->fetchAll(
                sprintf('SELECT id, LOWER(HEX(%s_hash)) AS h FROM %s WHERE project_id = ? AND %s_hash IN (%s)', $column, $table, $column, implode(', ', array_fill(0, count($hashes), 'UNHEX(?)'))),
                [$projectId, ...$hashes],
            );
            foreach ($found as $f) {
                $ids[(string) $f['h']] = (int) $f['id'];
            }
        }

        return $ids;
    }

    /**
     * @param list<DimensionRow> $rows
     * @param array<string, int> $ids
     */
    private function insertKeyed(string $table, string $idColumn, int $projectId, string $date, array $rows, array $ids): void
    {
        $merged = [];
        foreach ($rows as $row) {
            $id = $ids[hash('sha256', $row->key)] ?? null;
            if ($id !== null) {
                $merged[$id] = $row;
            }
        }
        foreach (array_chunk($merged, self::BATCH, true) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $id => $row) {
                $values[] = '(?, ?, ?, ?, ?, ?)';
                array_push($params, $projectId, $date, $id, $row->metrics->clicks, $row->metrics->impressions, $row->metrics->position);
            }
            $this->db->execute(
                sprintf('INSERT INTO %s (project_id, date, %s, clicks, impressions, position) VALUES %s', $table, $idColumn, implode(', ', $values)),
                $params,
            );
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
