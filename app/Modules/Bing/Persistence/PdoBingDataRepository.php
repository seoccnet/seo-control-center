<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Bing\BingCrawlDay;
use SeoControlCenter\Modules\Bing\BingDataRepository;
use SeoControlCenter\Modules\Bing\BingLinkCount;
use SeoControlCenter\Modules\Bing\BingSiteLink;

final readonly class PdoBingDataRepository implements BingDataRepository
{
    private const int INSERT_CHUNK = 500;

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function link(int $projectId): ?BingSiteLink
    {
        $row = $this->db->fetchOne('SELECT * FROM bing_project_sites WHERE project_id = ?', [$projectId]);

        return $row === null ? null : new BingSiteLink(
            (int) $row['project_id'],
            (int) $row['workspace_id'],
            (string) $row['site_url'],
            (string) $row['status'],
            $row['last_success_at'] !== null ? (string) $row['last_success_at'] : null,
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            (string) $row['linked_at'],
        );
    }

    public function saveLink(int $projectId, int $workspaceId, string $siteUrl, int $userId): void
    {
        $this->db->transaction(function () use ($projectId, $workspaceId, $siteUrl, $userId): void {
            $current = $this->link($projectId);
            if ($current !== null && $current->siteUrl !== $siteUrl) {
                $this->deleteData($projectId);
                $this->db->execute('DELETE FROM bing_crawl_stats WHERE project_id = ?', [$projectId]);
                $this->db->execute('DELETE FROM bing_link_counts WHERE project_id = ?', [$projectId]);
            }
            $this->db->execute(
                "INSERT INTO bing_project_sites (project_id, workspace_id, site_url, status, last_success_at, last_error, linked_by, linked_at)
                 VALUES (?, ?, ?, 'pending', NULL, NULL, ?, ?)
                 ON DUPLICATE KEY UPDATE last_success_at = IF(site_url = VALUES(site_url), last_success_at, NULL), site_url = VALUES(site_url),
                     status = 'pending', last_error = NULL, linked_by = VALUES(linked_by), linked_at = VALUES(linked_at)",
                [$projectId, $workspaceId, $siteUrl, $userId, $this->now()],
            );
        });
    }

    public function unlink(int $projectId): void
    {
        $this->db->execute('DELETE FROM bing_project_sites WHERE project_id = ?', [$projectId]);
    }

    public function linkedProjects(): array
    {
        return array_map(
            static fn(array $row): array => ['projectId' => (int) $row['project_id'], 'workspaceId' => (int) $row['workspace_id']],
            $this->db->fetchAll('SELECT b.project_id, b.workspace_id FROM bing_project_sites b JOIN projects p ON p.id = b.project_id WHERE p.deleted_at IS NULL ORDER BY b.project_id'),
        );
    }

    public function setStatus(int $projectId, string $status, ?string $error = null): void
    {
        $this->db->execute(
            'UPDATE bing_project_sites SET status = ?, last_error = ?, last_success_at = IF(? = ?, ?, last_success_at) WHERE project_id = ?',
            [$status, $error !== null ? mb_substr($error, 0, 500) : null, $status, BingSiteLink::OK, $this->now(), $projectId],
        );
    }

    public function replaceData(int $projectId, array $days, array $rows): void
    {
        $this->db->transaction(function () use ($projectId, $days, $rows): void {
            $this->deleteData($projectId);
            foreach (array_chunk($days, self::INSERT_CHUNK) as $chunk) {
                $params = [];
                foreach ($chunk as $day) {
                    array_push($params, $projectId, $day->date, $day->metrics->clicks, $day->metrics->impressions);
                }
                $this->db->execute('INSERT INTO bing_daily (project_id, date, clicks, impressions) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)')), $params);
            }
            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                $params = [];
                foreach ($chunk as $row) {
                    $key = mb_substr($row->row->key, 0, 2048);
                    array_push(
                        $params,
                        $projectId,
                        $row->dimension->value,
                        $row->weekEnd,
                        hash('sha256', $key, true),
                        $key,
                        $row->row->metrics->clicks,
                        $row->row->metrics->impressions,
                        round($row->row->metrics->position, 2)
                    );
                }
                $this->db->execute(
                    'INSERT INTO bing_weekly (project_id, dimension, week_end, key_hash, key_text, clicks, impressions, position) VALUES '
                        . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)')),
                    $params,
                );
            }
        });
    }

    public function dataSpan(int $projectId): ?array
    {
        $row = $this->db->fetchOne('SELECT MIN(date) AS first, MAX(date) AS last FROM bing_daily WHERE project_id = ?', [$projectId]);

        return $row === null || $row['last'] === null ? null : ['first' => (string) $row['first'], 'last' => (string) $row['last']];
    }

    public function daily(int $projectId, DateRange $range): array
    {
        return array_map(
            static fn(array $row): DailyMetrics => new DailyMetrics((string) $row['date'], new Metrics((int) $row['clicks'], (int) $row['impressions'], 0.0)),
            $this->db->fetchAll('SELECT date, clicks, impressions FROM bing_daily WHERE project_id = ? AND date BETWEEN ? AND ? ORDER BY date', [$projectId, $range->fromString(), $range->toString()]),
        );
    }

    public function totals(int $projectId, DateRange $range): Metrics
    {
        $row = $this->db->fetchOne(
            'SELECT COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(impressions), 0) AS impressions FROM bing_daily WHERE project_id = ? AND date BETWEEN ? AND ?',
            [$projectId, $range->fromString(), $range->toString()],
        );

        return new Metrics((int) ($row['clicks'] ?? 0), (int) ($row['impressions'] ?? 0), 0.0);
    }

    public function top(int $projectId, PerformanceDimension $dimension, DateRange $range, int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT MIN(key_text) AS key_text, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                    COALESCE(SUM(position * impressions) / NULLIF(SUM(impressions), 0), 0) AS position
               FROM bing_weekly
              WHERE project_id = ? AND dimension = ? AND week_end BETWEEN ? AND ?
              GROUP BY key_hash
              ORDER BY clicks DESC, impressions DESC, key_text
              LIMIT ' . max(1, $limit),
            [$projectId, $dimension->value, $range->fromString(), $range->toString()],
        );

        return array_map(
            static fn(array $row): DimensionRow => new DimensionRow((string) $row['key_text'], new Metrics((int) $row['clicks'], (int) $row['impressions'], max(0.0, (float) $row['position']))),
            $rows,
        );
    }

    public function weeks(int $projectId, DateRange $range): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(DISTINCT week_end) FROM bing_weekly WHERE project_id = ? AND week_end BETWEEN ? AND ?',
            [$projectId, $range->fromString(), $range->toString()],
        );
    }

    public function replaceCrawlData(int $projectId, array $days, array $links): void
    {
        $this->db->transaction(function () use ($projectId, $days, $links): void {
            $this->db->execute('DELETE FROM bing_crawl_stats WHERE project_id = ?', [$projectId]);
            $this->db->execute('DELETE FROM bing_link_counts WHERE project_id = ?', [$projectId]);
            foreach (array_chunk($days, self::INSERT_CHUNK) as $chunk) {
                $params = [];
                foreach ($chunk as $d) {
                    array_push($params, $projectId, $d->date, $d->crawledPages, $d->crawlErrors, $d->inIndex, $d->inLinks, $d->code2xx, $d->code3xx, $d->code4xx, $d->code5xx, $d->blockedByRobots, $d->otherCodes);
                }
                $this->db->execute(
                    'INSERT INTO bing_crawl_stats (project_id, date, crawled_pages, crawl_errors, in_index, in_links, code_2xx, code_3xx, code_4xx, code_5xx, blocked_by_robots, other_codes) VALUES '
                        . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                    $params,
                );
            }
            $unique = [];
            foreach ($links as $link) {
                $hash = hash('sha256', $link->url);
                $unique[$hash] = [$link->url, max($unique[$hash][1] ?? 0, $link->links)];
            }
            foreach (array_chunk($unique, self::INSERT_CHUNK, true) as $chunk) {
                $params = [];
                foreach ($chunk as $hash => [$url, $count]) {
                    array_push($params, $projectId, (string) $hash, $url, $count);
                }
                $this->db->execute(
                    'INSERT INTO bing_link_counts (project_id, url_hash, url, links) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, UNHEX(?), ?, ?)')),
                    $params,
                );
            }
        });
    }

    public function crawlStats(int $projectId, DateRange $range): array
    {
        return array_map(
            static fn(array $r): BingCrawlDay => new BingCrawlDay(
                (string) $r['date'],
                (int) $r['crawled_pages'],
                (int) $r['crawl_errors'],
                (int) $r['in_index'],
                (int) $r['in_links'],
                (int) $r['code_2xx'],
                (int) $r['code_3xx'],
                (int) $r['code_4xx'],
                (int) $r['code_5xx'],
                (int) $r['blocked_by_robots'],
                (int) $r['other_codes'],
            ),
            $this->db->fetchAll('SELECT * FROM bing_crawl_stats WHERE project_id = ? AND date BETWEEN ? AND ? ORDER BY date', [$projectId, $range->fromString(), $range->toString()]),
        );
    }

    public function linkCounts(int $projectId, int $limit): array
    {
        return array_map(
            static fn(array $r): BingLinkCount => new BingLinkCount((string) $r['url'], (int) $r['links']),
            $this->db->fetchAll('SELECT url, links FROM bing_link_counts WHERE project_id = ? ORDER BY links DESC, url LIMIT ' . max(1, $limit), [$projectId]),
        );
    }

    public function linkTotals(int $projectId): array
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS pages, COALESCE(SUM(links), 0) AS links FROM bing_link_counts WHERE project_id = ?', [$projectId]);

        return ['pages' => (int) ($row['pages'] ?? 0), 'links' => (int) ($row['links'] ?? 0)];
    }

    private function deleteData(int $projectId): void
    {
        $this->db->execute('DELETE FROM bing_daily WHERE project_id = ?', [$projectId]);
        $this->db->execute('DELETE FROM bing_weekly WHERE project_id = ?', [$projectId]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
