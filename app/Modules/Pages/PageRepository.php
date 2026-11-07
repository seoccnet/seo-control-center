<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

use SeoControlCenter\Domain\Shared\DateRange;

/** Stored, project-scoped read model. It never contacts a provider. */
interface PageRepository
{
    /** @return list<PageSummary> All known URLs, with bounded pagination. */
    public function list(int $projectId, DateRange $range, int $limit, int $offset, string $search = '', string $sort = 'url'): array;

    public function count(int $projectId, string $search = ''): int;

    /** @return list<PageSummary> Up to 10,001 rows to detect an oversized export. */
    public function export(int $projectId, DateRange $range, string $search = '', string $sort = 'url'): array;

    /**
     * One chunk of a queued export, after the last row of the previous chunk.
     * Rows are ordered by the sort metric (missing data last) and then the
     * binary URL, so paging by that key never repeats or skips a row.
     *
     * @param array{key: int, url: string}|null $after
     * @return list<PageSummary>
     */
    public function exportChunk(int $projectId, DateRange $range, string $search, string $sort, ?array $after, int $limit): array;

    public function find(int $projectId, string $url, DateRange $range): ?PageSummary;

    /** Number of days with completed page-dimension imports, including empty days. */
    public function importedPageDays(int $projectId, DateRange $range): int;

    /** @return list<PageQuery> */
    public function queries(int $projectId, DateRange $range, ?string $url = null): array;

    /** @return array{first: string, last: string}|null */
    public function queryPageSpan(int $projectId): ?array;

    /**
     * Search Console clicks, impressions and position per search, page and
     * week (weeks start on Monday) for the given searches, matched exactly.
     * At most 500 searches and 20,000 rows.
     *
     * @param list<string> $queries
     * @return list<array{query: string, url: string, week: string, clicks: int, impressions: int, position: float}>
     */
    public function weeklyQueryPages(int $projectId, DateRange $range, array $queries): array;

    /** @return list<array{keyword_id: string, keyword: string, position: ?int, checked_on: string, device: string}> */
    public function rankings(int $projectId, string $url): array;

    /** @return list<array{keyword: string, position: ?int, checked_on: string, device: string}> */
    public function citations(int $projectId, string $url): array;
}
