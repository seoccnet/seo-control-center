<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\FreshDay;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras;
use SeoControlCenter\Domain\SearchConsole\SearchType;
use SeoControlCenter\Domain\SearchConsole\Sitemap;
use SeoControlCenter\Domain\Shared\DateRange;

final readonly class PdoSearchConsoleExtras implements SearchConsoleExtras
{
    private const int BATCH = 500;

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function replaceFreshDays(int $projectId, array $days): void
    {
        $this->db->transaction(function () use ($projectId, $days): void {
            $this->db->execute('DELETE FROM search_console_fresh_daily WHERE project_id = ?', [$projectId]);
            foreach (array_chunk($days, self::BATCH) as $chunk) {
                $params = [];
                foreach ($chunk as $day) {
                    array_push($params, $projectId, $day->date, $day->metrics->clicks, $day->metrics->impressions, $day->metrics->position, $day->complete, $this->now());
                }
                $this->db->execute(
                    'INSERT INTO search_console_fresh_daily (project_id, date, clicks, impressions, position, complete, fetched_at) VALUES '
                        . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?)')),
                    $params,
                );
            }
        });
    }

    public function freshDays(int $projectId): array
    {
        // Final data wins: a day appears here only until its final totals are imported.
        // A day that was still today in Google's time zone when fetched is never complete,
        // even when Google did not name its first incomplete day (rows fetched before 0.23.1).
        return array_map(
            static fn(array $r): FreshDay => new FreshDay((string) $r['date'], new Metrics((int) $r['clicks'], (int) $r['impressions'], (float) $r['position']), (bool) $r['complete'] && (string) $r['date'] < (new \DateTimeImmutable((string) $r['fetched_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('Y-m-d')),
            $this->db->fetchAll(
                'SELECT f.date, f.clicks, f.impressions, f.position, f.complete, f.fetched_at FROM search_console_fresh_daily f
                  WHERE f.project_id = ?
                    AND f.date > COALESCE((SELECT MAX(d.date) FROM search_console_daily d WHERE d.project_id = ?), \'1000-01-01\')
                  ORDER BY f.date',
                [$projectId, $projectId],
            ),
        );
    }

    public function upsertSearchTypeDays(int $projectId, SearchType $type, array $days): void
    {
        foreach (array_chunk($days, self::BATCH) as $chunk) {
            $params = [];
            foreach ($chunk as $day) {
                array_push($params, $projectId, $type->value, $day->date, $day->metrics->clicks, $day->metrics->impressions, $day->metrics->position);
            }
            $this->db->execute(
                'INSERT INTO search_console_search_types (project_id, search_type, date, clicks, impressions, position) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)')) . '
                 ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions), position = VALUES(position)',
                $params,
            );
        }
    }

    public function searchTypeTotals(int $projectId, DateRange $range): array
    {
        $totals = [];
        foreach ($this->db->fetchAll(
            'SELECT search_type, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                    COALESCE(SUM(position * impressions) / NULLIF(SUM(impressions), 0), 0) AS position
               FROM search_console_search_types
              WHERE project_id = ? AND date BETWEEN ? AND ?
              GROUP BY search_type',
            [$projectId, $range->fromString(), $range->toString()],
        ) as $r) {
            $type = SearchType::tryFrom((string) $r['search_type']);
            if ($type !== null) {
                $totals[$type->value] = new Metrics((int) $r['clicks'], (int) $r['impressions'], max(0.0, (float) $r['position']));
            }
        }

        return $totals;
    }

    public function searchTypeLastDate(int $projectId): ?string
    {
        $date = $this->db->fetchValue('SELECT MAX(date) FROM search_console_search_types WHERE project_id = ?', [$projectId]);

        return is_string($date) ? $date : null;
    }

    public function searchTypeDaily(int $projectId, SearchType $type, DateRange $range): array
    {
        return array_map(
            static fn(array $r): DailyMetrics => new DailyMetrics((string) $r['date'], new Metrics((int) $r['clicks'], (int) $r['impressions'], (float) $r['position'])),
            $this->db->fetchAll(
                'SELECT date, clicks, impressions, position FROM search_console_search_types WHERE project_id = ? AND search_type = ? AND date BETWEEN ? AND ? ORDER BY date',
                [$projectId, $type->value, $range->fromString(), $range->toString()],
            ),
        );
    }

    public function sitemapsRefreshedAt(int $projectId): ?string
    {
        $at = $this->db->fetchValue('SELECT MAX(refreshed_at) FROM search_console_sitemaps WHERE project_id = ?', [$projectId]);

        return is_string($at) ? $at : null;
    }

    public function replaceSitemaps(int $projectId, array $sitemaps): void
    {
        $this->db->transaction(function () use ($projectId, $sitemaps): void {
            $this->db->execute('DELETE FROM search_console_sitemaps WHERE project_id = ?', [$projectId]);
            $unique = [];
            foreach ($sitemaps as $sitemap) {
                $unique[hash('sha256', $sitemap->url)] = $sitemap;
            }
            foreach (array_chunk($unique, self::BATCH, true) as $chunk) {
                $params = [];
                foreach ($chunk as $hash => $s) {
                    array_push($params, $projectId, (string) $hash, $s->url, $s->isIndex, $s->isPending, $s->lastSubmitted, $s->lastDownloaded, $s->errors, $s->warnings, $s->submittedUrls, $this->now());
                }
                $this->db->execute(
                    'INSERT INTO search_console_sitemaps (project_id, url_hash, url, is_index, is_pending, last_submitted, last_downloaded, errors, warnings, submitted_urls, refreshed_at) VALUES '
                        . implode(', ', array_fill(0, count($chunk), '(?, UNHEX(?), ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                    $params,
                );
            }
        });
    }

    public function sitemaps(int $projectId): array
    {
        return array_map(
            static fn(array $r): Sitemap => new Sitemap(
                (string) $r['url'],
                (bool) $r['is_index'],
                (bool) $r['is_pending'],
                $r['last_submitted'] !== null ? (string) $r['last_submitted'] : null,
                $r['last_downloaded'] !== null ? (string) $r['last_downloaded'] : null,
                (int) $r['errors'],
                (int) $r['warnings'],
                (int) $r['submitted_urls'],
            ),
            $this->db->fetchAll(
                'SELECT url, is_index, is_pending, last_submitted, last_downloaded, errors, warnings, submitted_urls FROM search_console_sitemaps
                  WHERE project_id = ? ORDER BY (errors + warnings > 0) DESC, url LIMIT 200',
                [$projectId],
            ),
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
