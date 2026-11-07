<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Pages\PageQuery;
use SeoControlCenter\Modules\Pages\PageRepository;
use SeoControlCenter\Modules\Pages\PageSummary;

final class InMemoryPages implements PageRepository
{
    /** @var array<int, list<PageSummary>> */
    public array $pages = [];
    /** @var array<int, list<PageQuery>> */
    public array $queryRows = [];
    /** @var array<int, array{first: string, last: string}> */
    public array $spans = [];
    /** @var array<int, list<string>> */
    public array $importDays = [];
    /** @var array<int, array<string, list<PageSummary>>> Range start => page metrics. */
    public array $periodPages = [];
    /** @var array<int, array<string, list<array{keyword_id: string, keyword: string, position: ?int, checked_on: string, device: string}>>> */
    public array $rankingRows = [];
    /** @var array<int, array<string, list<array{keyword: string, position: ?int, checked_on: string, device: string}>>> */
    public array $citationRows = [];

    public function list(int $projectId, DateRange $range, int $limit, int $offset, string $search = '', string $sort = 'url'): array
    {
        return array_slice($this->selected($projectId, $search, $sort), max(0, $offset), max(1, min(100, $limit)));
    }
    public function export(int $projectId, DateRange $range, string $search = '', string $sort = 'url'): array
    {
        return array_slice($this->selected($projectId, $search, $sort), 0, 10001);
    }
    public function exportChunk(int $projectId, DateRange $range, string $search, string $sort, ?array $after, int $limit): array
    {
        $key = static fn(PageSummary $row): int => match ($sort) {
            'google_clicks' => $row->google?->clicks, 'bing_clicks' => $row->bing?->clicks, 'impressions' => $row->google?->impressions, default => 0,
        } ?? -1;
        $rows = $after === null ? $this->selected($projectId, $search, $sort) : array_values(array_filter(
            $this->selected($projectId, $search, $sort),
            static fn(PageSummary $row): bool => $key($row) < $after['key'] || ($key($row) === $after['key'] && strcmp($row->url, $after['url']) > 0)
        ));
        return array_slice($rows, 0, max(1, min(10000, $limit)));
    }
    /** @return list<PageSummary> */
    private function selected(int $projectId, string $search, string $sort): array
    {
        $rows = array_values(array_filter($this->pages[$projectId] ?? [], static fn(PageSummary $row): bool => $search === '' || mb_stripos($row->url, $search) !== false));
        usort($rows, static function (PageSummary $a, PageSummary $b) use ($sort): int {
            $value = static fn(PageSummary $row): ?int => match ($sort) {
                'google_clicks' => $row->google?->clicks, 'bing_clicks' => $row->bing?->clicks, 'impressions' => $row->google?->impressions, default => null,
            };
            return $sort === 'url' ? strcmp($a->url, $b->url) : (($value($b) ?? -1) <=> ($value($a) ?? -1) ?: strcmp($a->url, $b->url));
        });
        return $rows;
    }
    /** @var array<int, int> Test helper: a reported match count, to exercise limits without huge fixtures. */
    public array $countOverrides = [];
    public function count(int $projectId, string $search = ''): int
    {
        if (isset($this->countOverrides[$projectId])) {
            return $this->countOverrides[$projectId];
        }
        return count(array_filter($this->pages[$projectId] ?? [], static fn(PageSummary $row): bool => $search === '' || mb_stripos($row->url, $search) !== false));
    }
    public function find(int $projectId, string $url, DateRange $range): ?PageSummary
    {
        foreach ($this->periodPages[$projectId][$range->fromString()] ?? $this->pages[$projectId] ?? [] as $page) {
            if ($page->url === $url) {
                return $page;
            }
        }
        return null;
    }
    public function importedPageDays(int $projectId, DateRange $range): int
    {
        return count(array_filter(array_unique($this->importDays[$projectId] ?? []), $range->contains(...)));
    }
    public function queries(int $projectId, DateRange $range, ?string $url = null): array
    {
        return array_values(array_filter($this->queryRows[$projectId] ?? [], static fn(PageQuery $q): bool => $url === null || $q->url === $url));
    }
    /** @var array<int, list<array{query: string, url: string, week: string, clicks: int, impressions: int, position: float}>> */
    public array $weekly = [];

    public function weeklyQueryPages(int $projectId, DateRange $range, array $queries): array
    {
        $wanted = array_fill_keys($queries, true);

        return array_values(array_filter($this->weekly[$projectId] ?? [], static fn(array $r): bool => isset($wanted[$r['query']]) && $r['week'] >= (new \DateTimeImmutable($range->fromString()))->modify('-6 days')->format('Y-m-d') && $r['week'] <= $range->toString()));
    }

    public function queryPageSpan(int $projectId): ?array
    {
        return $this->spans[$projectId] ?? null;
    }
    public function rankings(int $projectId, string $url): array
    {
        return $this->rankingRows[$projectId][$url] ?? [];
    }
    public function citations(int $projectId, string $url): array
    {
        return $this->citationRows[$projectId][$url] ?? [];
    }
}
