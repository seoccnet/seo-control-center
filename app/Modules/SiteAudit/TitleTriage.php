<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Domain\Opportunities\CtrModel;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Which long titles to shorten first (0.48.0). A title cut off in the
 * results is one likely reason a page gets fewer clicks than the site
 * usually gets at that position; this ranks the pages with a long title
 * by that shortfall, on the site's own click curve, and groups them by
 * page type (the first path segment). Stored data only; never calls
 * providers.
 */
final readonly class TitleTriage
{
    /** Long-title pages read from one audit. */
    public const int LIMIT = 2000;
    /** Only page-one positions show a title worth fixing for clicks. */
    public const int MAX_POSITION = 10;
    /** Pages with fewer impressions are too thin to compare. */
    public const int MIN_IMPRESSIONS = 100;
    private const int ROWS = 5000;

    public function __construct(
        private SiteAuditRepository $audits,
        private SearchPerformanceReader $google,
    ) {}

    /**
     * @return array{range: ?DateRange, basis: string, total: int, without_data: int, groups: list<array{template: string, titles: int, measured: int, shortfall: int}>, pages: list<array{url: string, title: string, length: int, position: float, impressions: int, clicks: int, expected: int, shortfall: int}>}
     */
    public function triage(Project $project, SiteAudit $audit): array
    {
        $issues = $this->audits->issues($audit->id, IssueType::TitleTooLong, 0, self::LIMIT);
        $pages = $this->audits->pagesById($audit->id, array_values(array_unique(array_column($issues, 'pageId'))));
        $titled = [];
        foreach ($pages as $page) {
            if ($page->title !== null) {
                $titled[] = ['url' => $page->url, 'title' => $page->title];
            }
        }
        $span = $this->google->dataSpan($project->id);
        $range = $span !== null ? DateRange::endingOn($span['last'], 28) : null;
        $rows = $range !== null ? $this->google->top($project->id, PerformanceDimension::Page, $range, self::ROWS) : [];
        $model = $range !== null ? CtrModel::train($this->google->top($project->id, PerformanceDimension::Query, $range, self::ROWS), $project->primaryDomain) : new CtrModel();

        return ['range' => $range, 'basis' => $model->hasSamples() ? 'your site’s own clicks by position' : 'a general click curve (too few of your own searches yet)', ...self::rank($titled, $rows, $model)];
    }

    /**
     * @param list<array{url: string, title: string}> $titled pages with a long title
     * @param list<DimensionRow> $rows Google page rows for the window
     * @return array{total: int, without_data: int, groups: list<array{template: string, titles: int, measured: int, shortfall: int}>, pages: list<array{url: string, title: string, length: int, position: float, impressions: int, clicks: int, expected: int, shortfall: int}>}
     */
    public static function rank(array $titled, array $rows, CtrModel $model): array
    {
        $metrics = [];
        foreach ($rows as $row) {
            $metrics[self::key($row->key)] = $row->metrics;
        }
        $groups = [];
        $ranked = [];
        $withoutData = 0;
        foreach ($titled as $page) {
            $template = self::template($page['url']);
            $groups[$template] ??= ['template' => $template, 'titles' => 0, 'measured' => 0, 'shortfall' => 0];
            $groups[$template]['titles']++;
            $m = $metrics[self::key($page['url'])] ?? null;
            if ($m === null || $m->impressions < self::MIN_IMPRESSIONS) {
                $withoutData++;
                continue;
            }
            $position = (int) round($m->position);
            if ($position < 1 || $position > self::MAX_POSITION) {
                continue;
            }
            $groups[$template]['measured']++;
            $expected = (int) round($m->impressions * $model->expected($position));
            $shortfall = max(0, $expected - $m->clicks);
            $groups[$template]['shortfall'] += $shortfall;
            if ($shortfall > 0) {
                $ranked[] = ['url' => $page['url'], 'title' => $page['title'], 'length' => mb_strlen($page['title']), 'position' => round($m->position, 1), 'impressions' => $m->impressions, 'clicks' => $m->clicks, 'expected' => $expected, 'shortfall' => $shortfall];
            }
        }
        usort($ranked, static fn(array $a, array $b): int => [$b['shortfall'], $a['url']] <=> [$a['shortfall'], $b['url']]);
        $groups = array_values($groups);
        usort($groups, static fn(array $a, array $b): int => [$b['shortfall'], $b['titles'], $a['template']] <=> [$a['shortfall'], $a['titles'], $b['template']]);

        return ['total' => count($titled), 'without_data' => $withoutData, 'groups' => $groups, 'pages' => array_slice($ranked, 0, 20)];
    }

    /** The page type: the first path segment, or "/" for the home page. */
    public static function template(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return '/';
        }
        $segments = explode('/', $path);

        return count($segments) === 1 ? '/' . $segments[0] : '/' . $segments[0] . '/…';
    }

    /** Host without www, path without a trailing slash, query kept. */
    private static function key(string $url): string
    {
        $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return $host . rtrim((string) parse_url($url, PHP_URL_PATH), '/') . ($query !== '' ? '?' . $query : '');
    }
}
