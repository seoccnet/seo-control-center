<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\RowFilter;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Orphan pages (1.7.0): pages Google shows in search (Search Console, last
 * 90 days) that no page of the latest completed audit links to. Pages only
 * found through the sitemap are orphans for certain; pages the audit never
 * reached are orphans only when the audit was not cut by its page limit.
 */
final readonly class OrphanPagesService
{
    public const int DAYS = 90;
    public const int ROWS = 500;
    /** Search Console pages read, by clicks. */
    private const int READ = 5000;

    public const string SITEMAP_ONLY = 'sitemap_only';
    public const string NOT_REACHED = 'not_reached';
    public const string BEYOND_LIMIT = 'beyond_limit';

    public function __construct(
        private SiteAuditRepository $audits,
        private SearchPerformanceReader $search,
    ) {}

    /**
     * @return array{
     *     audited: bool, searchData: bool, complete: bool, range: ?DateRange, checked: int,
     *     counts: array<string, int>, sitemapNoTraffic: int,
     *     rows: list<array{url: string, kind: string, clicks: int, impressions: int, position: float}>
     * }
     */
    public function report(Project $project): array
    {
        $audit = $this->audits->latest($project->id, 'completed');
        $span = $this->search->dataSpan($project->id);
        $counts = [self::SITEMAP_ONLY => 0, self::NOT_REACHED => 0, self::BEYOND_LIMIT => 0];
        $empty = ['audited' => $audit !== null, 'searchData' => $span !== null, 'complete' => false, 'range' => null, 'checked' => 0, 'counts' => $counts, 'sitemapNoTraffic' => 0, 'rows' => []];
        if ($audit === null || $span === null) {
            return $empty;
        }
        $complete = $audit->pagesFound < $audit->maxPages;
        $pages = [];
        // Only addresses on the hosts the audit crawled: other hosts of a domain property are not part of it.
        $hosts = [];
        foreach ($this->audits->allPages($audit->id) as $page) {
            $pages[TitleRewriteService::key($page->url)] = $page;
            if ($page->isLinked() && $page->state === 'done') {
                $hosts[self::host($page->url)] = true;
            }
        }

        $range = DateRange::endingOn($span['last'], self::DAYS);
        $rows = [];
        $seen = [];
        $checked = 0;
        for ($offset = 0; $offset < self::READ; $offset += 500) {
            $batch = $this->search->matching($project->id, PerformanceDimension::Page, $range, new RowFilter(), 500, $offset);
            foreach ($batch['rows'] as $row) {
                if (!isset($hosts[self::host($row->key)])) {
                    continue;
                }
                $key = TitleRewriteService::key($row->key);
                $seen[$key] = true;
                ++$checked;
                $page = $pages[$key] ?? null;
                if ($page !== null && ($page->isLinked() || $page->state !== 'done' || $page->isRedirect() || ($page->statusCode ?? 0) >= 300)) {
                    continue;
                }
                $kind = $page !== null ? self::SITEMAP_ONLY : ($complete ? self::NOT_REACHED : self::BEYOND_LIMIT);
                ++$counts[$kind];
                $rows[] = ['url' => $row->key, 'kind' => $kind, 'clicks' => $row->current->clicks, 'impressions' => $row->current->impressions, 'position' => $row->current->position];
            }
            if (count($batch['rows']) < 500) {
                break;
            }
        }
        // The audit's own "not linked internally" pages that Google showed nothing for.
        $sitemapNoTraffic = 0;
        foreach ($pages as $key => $page) {
            if (!$page->isLinked() && $page->state === 'done' && $page->isHtml() && ($page->statusCode ?? 0) < 300 && !isset($seen[$key])) {
                ++$sitemapNoTraffic;
            }
        }
        usort($rows, static fn(array $a, array $b): int => [$a['kind'] === self::BEYOND_LIMIT, -$a['clicks'], -$a['impressions'], $a['url']] <=> [$b['kind'] === self::BEYOND_LIMIT, -$b['clicks'], -$b['impressions'], $b['url']]);

        return [...$empty, 'complete' => $complete, 'range' => $range, 'checked' => $checked, 'counts' => $counts, 'sitemapNoTraffic' => $sitemapNoTraffic, 'rows' => array_slice($rows, 0, self::ROWS)];
    }

    private static function host(string $url): string
    {
        // Scheme and host: an old http:// address redirects to the crawled https:// one.
        return strtolower((string) parse_url($url, PHP_URL_SCHEME) . '://' . (string) parse_url($url, PHP_URL_HOST));
    }
}
