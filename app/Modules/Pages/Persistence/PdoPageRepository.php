<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Pages\PageQuery;
use SeoControlCenter\Modules\Pages\PageRepository;
use SeoControlCenter\Modules\Pages\PageSummary;

/** A read model across normalized source tables; no provider payloads or writes. */
final readonly class PdoPageRepository implements PageRepository
{
    public function __construct(private Connection $db) {}

    /** @return array{string, list<int>} */
    private function catalog(int $projectId): array
    {
        return ["SELECT url COLLATE utf8mb4_bin AS url FROM pages WHERE project_id = ?
            UNION SELECT ap.url COLLATE utf8mb4_bin FROM site_audit_pages ap JOIN site_audits a ON a.id = ap.audit_id
                WHERE a.id = (SELECT id FROM site_audits WHERE project_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1)
            UNION SELECT url COLLATE utf8mb4_bin FROM ranking_snapshots WHERE project_id = ? AND url IS NOT NULL
            UNION SELECT own_url COLLATE utf8mb4_bin FROM ai_overview_snapshots WHERE project_id = ? AND own_url IS NOT NULL
            UNION SELECT key_text COLLATE utf8mb4_bin FROM bing_weekly WHERE project_id = ? AND dimension = 'page'
            UNION SELECT j.url COLLATE utf8mb4_bin FROM research_results r,
                JSON_TABLE(r.items, '$[*]' COLUMNS (url VARCHAR(2048) CHARACTER SET utf8mb4 PATH '$.target_url')) j
                WHERE r.project_id = ? AND r.kind IN ('bl_links', 'bl_lost_links') AND r.fetched_at IS NOT NULL AND j.url IS NOT NULL
            UNION SELECT url COLLATE utf8mb4_bin FROM ga4_landing_pages WHERE project_id = ?
            UNION SELECT url COLLATE utf8mb4_bin FROM google_inspections WHERE project_id = ?",
            array_fill(0, 8, $projectId)];
    }

    public function count(int $projectId, string $search = ''): int
    {
        [$sql, $params] = $this->catalog($projectId);
        [$filter, $filterParams] = self::searchFilter($search);
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM (' . $sql . ') urls' . $filter, [...$params, ...$filterParams]);
    }

    public function list(int $projectId, DateRange $range, int $limit, int $offset, string $search = '', string $sort = 'url'): array
    {
        return $this->selected($projectId, $range, max(1, min(100, $limit)), max(0, $offset), $search, $sort);
    }

    public function export(int $projectId, DateRange $range, string $search = '', string $sort = 'url'): array
    {
        return $this->selected($projectId, $range, 10001, 0, $search, $sort);
    }

    public function exportChunk(int $projectId, DateRange $range, string $search, string $sort, ?array $after, int $limit): array
    {
        [$sql, $params] = $this->catalog($projectId);
        [$filter, $filterParams] = self::searchFilter($search);
        $key = self::sortKey($sort);
        $where = '';
        $cursor = [];
        if ($after !== null) {
            [$where, $cursor] = $key === null
                ? [' WHERE u.url > ?', [$after['url']]]
                : [' WHERE (' . $key . ' < ? OR (' . $key . ' = ? AND u.url > ?))', [$after['key'], $after['key'], $after['url']]];
        }
        $order = ($key === null ? '' : $key . ' DESC, ') . 'u.url';
        return $this->summaries($projectId, $range, 'SELECT url FROM (' . $sql . ') urls' . $filter, [...$params, ...$filterParams], $order, max(1, min(10000, $limit)), 0, $where, $cursor);
    }

    /** The metric a sort orders by, with missing data (-1) last; null for URL order. */
    private static function sortKey(string $sort): ?string
    {
        return match ($sort) {
            'google_clicks' => 'COALESCE(g.clicks, -1)',
            'bing_clicks' => 'COALESCE(b.clicks, -1)',
            'impressions' => 'COALESCE(g.impressions, -1)',
            default => null,
        };
    }

    /** @return list<PageSummary> */
    private function selected(int $projectId, DateRange $range, int $limit, int $offset, string $search, string $sort): array
    {
        [$sql, $params] = $this->catalog($projectId);
        [$filter, $filterParams] = self::searchFilter($search);
        $order = match ($sort) {
            'google_clicks' => 'gc DESC, u.url',
            'bing_clicks' => 'bc DESC, u.url',
            'impressions' => 'gi DESC, u.url',
            default => 'u.url',
        };
        return $this->summaries($projectId, $range, 'SELECT url FROM (' . $sql . ') urls' . $filter, [...$params, ...$filterParams], $order, $limit, $offset);
    }

    /** @return array{string, list<string>} */
    private static function searchFilter(string $search): array
    {
        return $search === '' ? ['', []] : [" WHERE LOWER(url) LIKE ? ESCAPE '!'", ['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%']];
    }

    public function find(int $projectId, string $url, DateRange $range): ?PageSummary
    {
        [$sql, $params] = $this->catalog($projectId);
        return $this->summaries($projectId, $range, 'SELECT url FROM (' . $sql . ') urls WHERE BINARY url = BINARY ? LIMIT 1', [...$params, $url])[0] ?? null;
    }

    public function importedPageDays(int $projectId, DateRange $range): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM search_console_import_days WHERE project_id = ? AND date BETWEEN ? AND ?', [$projectId, $range->fromString(), $range->toString()]);
    }

    /**
     * @param list<int|string> $params
     * @param list<int|string> $whereParams
     * @return list<PageSummary>
     */
    private function summaries(int $projectId, DateRange $range, string $catalog, array $params, string $order = 'u.url', ?int $limit = null, int $offset = 0, string $where = '', array $whereParams = []): array
    {
        $rows = $this->db->fetchAll(
            "SELECT u.url, g.clicks AS gc, g.impressions AS gi, g.position AS gp, b.clicks AS bc, b.impressions AS bi, b.position AS bp
            FROM ($catalog) u
            LEFT JOIN (
                SELECT p.url_hash, SUM(s.clicks) AS clicks, SUM(s.impressions) AS impressions,
                    COALESCE(SUM(s.position * s.impressions) / NULLIF(SUM(s.impressions), 0), 0) AS position
                FROM search_console_pages s JOIN pages p ON p.id = s.page_id AND p.project_id = s.project_id
                WHERE s.project_id = ? AND s.date BETWEEN ? AND ? GROUP BY p.url_hash
            ) g ON g.url_hash = UNHEX(SHA2(u.url, 256))
            LEFT JOIN (
                SELECT key_hash, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                    COALESCE(SUM(position * impressions) / NULLIF(SUM(impressions), 0), 0) AS position
                FROM bing_weekly WHERE project_id = ? AND dimension = 'page' AND week_end BETWEEN ? AND ? GROUP BY key_hash
            ) b ON b.key_hash = UNHEX(SHA2(u.url, 256))" . $where . ' ORDER BY ' . $order . ($limit !== null ? ' LIMIT ' . $limit . ' OFFSET ' . $offset : ''),
            [...$params, $projectId, $range->fromString(), $range->toString(), $projectId, $range->fromString(), $range->toString(), ...$whereParams]
        );
        return array_map(static fn(array $r): PageSummary => new PageSummary(
            (string) $r['url'],
            $r['gc'] === null ? null : new Metrics((int) $r['gc'], (int) $r['gi'], (float) $r['gp']),
            $r['bc'] === null ? null : new Metrics((int) $r['bc'], (int) $r['bi'], (float) $r['bp'])
        ), $rows);
    }

    public function queries(int $projectId, DateRange $range, ?string $url = null): array
    {
        $params = [$projectId, $range->fromString(), $range->toString()];
        $filter = '';
        if ($url !== null) {
            $filter = ' AND p.url_hash = UNHEX(?)';
            $params[] = hash('sha256', $url);
        }
        return array_map(static fn(array $r): PageQuery => new PageQuery(
            (string) $r['query'],
            (string) $r['url'],
            new Metrics((int) $r['clicks'], (int) $r['impressions'], (float) $r['position'])
        ), $this->db->fetchAll(
            'SELECT q.query, p.url, SUM(s.clicks) AS clicks, SUM(s.impressions) AS impressions,
                COALESCE(SUM(s.position * s.impressions) / NULLIF(SUM(s.impressions), 0), 0) AS position
             FROM search_console_query_pages s
             JOIN search_queries q ON q.id = s.query_id AND q.project_id = s.project_id
             JOIN pages p ON p.id = s.page_id AND p.project_id = s.project_id
             WHERE s.project_id = ? AND s.date BETWEEN ? AND ?' . $filter . '
             GROUP BY s.query_id, s.page_id, q.query, p.url ORDER BY impressions DESC, clicks DESC LIMIT 5000',
            $params
        ));
    }

    public function weeklyQueryPages(int $projectId, DateRange $range, array $queries): array
    {
        $queries = array_values(array_unique(array_slice($queries, 0, 500)));
        if ($queries === []) {
            return [];
        }

        return array_map(static fn(array $r): array => [
            'query' => (string) $r['query'],
            'url' => (string) $r['url'],
            'week' => (string) $r['week'],
            'clicks' => (int) $r['clicks'],
            'impressions' => (int) $r['impressions'],
            'position' => (float) $r['position'],
        ], $this->db->fetchAll(
            'SELECT q.query, p.url, DATE_SUB(s.date, INTERVAL WEEKDAY(s.date) DAY) AS week, SUM(s.clicks) AS clicks, SUM(s.impressions) AS impressions,
                COALESCE(SUM(s.position * s.impressions) / NULLIF(SUM(s.impressions), 0), 0) AS position
             FROM search_console_query_pages s
             JOIN search_queries q ON q.id = s.query_id AND q.project_id = s.project_id
             JOIN pages p ON p.id = s.page_id AND p.project_id = s.project_id
             WHERE s.project_id = ? AND s.date BETWEEN ? AND ? AND q.query_hash IN (' . implode(', ', array_fill(0, count($queries), 'UNHEX(?)')) . ')
             GROUP BY s.query_id, s.page_id, week, q.query, p.url ORDER BY q.query, week, impressions DESC LIMIT 20000',
            [$projectId, $range->fromString(), $range->toString(), ...array_map(static fn(string $q): string => hash('sha256', $q), $queries)],
        ));
    }

    public function queryPageSpan(int $projectId): ?array
    {
        $r = $this->db->fetchOne('SELECT MIN(date) AS first, MAX(date) AS last FROM search_console_query_page_days WHERE project_id = ?', [$projectId]);
        return $r === null || $r['last'] === null ? null : ['first' => (string) $r['first'], 'last' => (string) $r['last']];
    }

    public function rankings(int $projectId, string $url): array
    {
        return array_map(
            static fn(array $r): array => ['keyword_id' => (string) $r['public_id'], 'keyword' => (string) $r['keyword'],
                'position' => $r['position'] === null ? null : (int) $r['position'], 'checked_on' => (string) $r['checked_on'], 'device' => (string) $r['device']],
            $this->db->fetchAll('SELECT k.public_id, k.keyword, s.position, s.checked_on, s.device
                FROM ranking_snapshots s JOIN project_keywords k ON k.id = s.project_keyword_id AND k.project_id = s.project_id
                WHERE s.project_id = ? AND BINARY s.url = BINARY ? AND s.checked_on = (
                    SELECT MAX(x.checked_on) FROM ranking_snapshots x WHERE x.project_id = s.project_id AND x.project_keyword_id = s.project_keyword_id AND x.device = s.device)
                ORDER BY s.position, k.keyword LIMIT 500', [$projectId, $url])
        );
    }

    public function citations(int $projectId, string $url): array
    {
        return array_map(
            static fn(array $r): array => ['keyword' => (string) $r['keyword'], 'position' => $r['own_position'] === null ? null : (int) $r['own_position'],
                'checked_on' => (string) $r['checked_on'], 'device' => (string) $r['device']],
            $this->db->fetchAll('SELECT k.keyword, s.own_position, s.checked_on, s.device
                FROM ai_overview_snapshots s JOIN project_keywords k ON k.id = s.project_keyword_id AND k.project_id = s.project_id
                WHERE s.project_id = ? AND BINARY s.own_url = BINARY ? AND s.checked_on = (
                    SELECT MAX(x.checked_on) FROM ai_overview_snapshots x WHERE x.project_id = s.project_id AND x.project_keyword_id = s.project_keyword_id AND x.device = s.device)
                ORDER BY s.own_position, k.keyword LIMIT 500', [$projectId, $url])
        );
    }
}
