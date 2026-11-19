<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Domain\InternalLinks\LinkRank;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\RowFilter;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Internal link strength (1.8.0): how much of the site's internal linking
 * each page of the latest completed audit receives (PageRank over the
 * audit's links, as a percentile), next to its Search Console clicks of the
 * last 90 days. Points out pages that earn clicks but get little linking,
 * and strongly linked pages that Google shows for nothing.
 */
final readonly class LinkStrengthService
{
    public const int DAYS = 90;
    public const int ROWS = 300;
    /** Below this percentile a page counts as weakly linked. */
    public const int WEAK = 50;
    /** From this percentile a page counts as strongly linked. */
    public const int STRONG = 75;
    /** Pages deeper than this many clicks from the start page count as buried. */
    public const int DEEP = 3;
    /** Clicked pages count as earning from this many clicks, at least. */
    public const int MIN_CLICKS = 5;
    private const int READ = 5000;

    public const string UNDERLINKED = 'underlinked';
    public const string NO_SEARCHES = 'no_searches';
    public const string ALL = 'all';

    public function __construct(
        private SiteAuditRepository $audits,
        private SearchPerformanceReader $search,
    ) {}

    /**
     * @return array{
     *     audited: bool, searchData: bool, range: ?DateRange, pages: int, links: int, show: string, counts: array<string, int>,
     *     rows: list<array{url: string, strength: int, inbound: int, depth: ?int, clicks: ?int, impressions: ?int, indexable: bool, reason: ?string}>
     * }
     */
    public function report(Project $project, string $view): array
    {
        $view = in_array($view, [self::UNDERLINKED, self::NO_SEARCHES, self::ALL], true) ? $view : self::UNDERLINKED;
        $audit = $this->audits->latest($project->id, 'completed');
        $span = $this->search->dataSpan($project->id);
        $result = ['audited' => $audit !== null, 'searchData' => $span !== null, 'range' => null, 'pages' => 0, 'links' => 0, 'show' => $view, 'counts' => [self::UNDERLINKED => 0, self::NO_SEARCHES => 0, self::ALL => 0], 'rows' => []];
        if ($audit === null) {
            return $result;
        }
        /** @var array<int, AuditPage> $pages */
        $pages = [];
        foreach ($this->audits->allPages($audit->id) as $page) {
            if ($page->state === 'done' && $page->isHtml() && ($page->statusCode ?? 0) >= 200 && ($page->statusCode ?? 0) < 300) {
                $pages[$page->id] = $page;
            }
        }
        $edges = $this->audits->links($audit->id);
        $percentiles = LinkRank::percentiles(LinkRank::ranks(array_keys($pages), $edges));
        $inbound = $this->audits->inboundCounts($audit->id);
        $result['pages'] = count($pages);
        $result['links'] = count($edges);

        $metrics = [];
        if ($span !== null) {
            $range = DateRange::endingOn($span['last'], self::DAYS);
            $result['range'] = $range;
            for ($offset = 0; $offset < self::READ; $offset += 500) {
                $batch = $this->search->matching($project->id, PerformanceDimension::Page, $range, new RowFilter(sort: 'impressions'), 500, $offset);
                foreach ($batch['rows'] as $row) {
                    $key = TitleRewriteService::key($row->key);
                    $metrics[$key] = [
                        'clicks' => ($metrics[$key]['clicks'] ?? 0) + $row->current->clicks,
                        'impressions' => ($metrics[$key]['impressions'] ?? 0) + $row->current->impressions,
                    ];
                }
                if (count($batch['rows']) < 500) {
                    break;
                }
            }
        }
        // "Earning" pages: the top quarter of pages with clicks, and at least MIN_CLICKS.
        $clicked = array_values(array_filter(array_column($metrics, 'clicks'), static fn(int $c): bool => $c > 0));
        rsort($clicked);
        $earning = max(self::MIN_CLICKS, $clicked !== [] ? $clicked[intdiv(count($clicked) - 1, 4)] : PHP_INT_MAX);
        $startKey = TitleRewriteService::key($audit->startUrl);

        $rows = [];
        foreach ($pages as $id => $page) {
            $key = TitleRewriteService::key($page->url);
            $indexable = !$page->noindex && ($page->canonical === null || $page->canonical === '' || TitleRewriteService::key($page->canonical) === $key);
            $m = $span !== null ? ($metrics[$key] ?? ['clicks' => 0, 'impressions' => 0]) : null;
            $depth = $page->depth === AuditPage::UNLINKED ? null : $page->depth;
            $strength = $percentiles[$id] ?? 0;
            $reason = null;
            if ($m !== null && $indexable && $m['clicks'] >= $earning && ($strength < self::WEAK || $depth === null || $depth > self::DEEP)) {
                $reason = self::UNDERLINKED;
            } elseif ($m !== null && $strength >= self::STRONG && $m['impressions'] === 0 && $key !== $startKey) {
                $reason = self::NO_SEARCHES;
            }
            if ($reason !== null) {
                ++$result['counts'][$reason];
            }
            ++$result['counts'][self::ALL];
            if ($view !== self::ALL && $reason !== $view) {
                continue;
            }
            $rows[] = ['url' => $page->url, 'strength' => $strength, 'inbound' => $inbound[$id] ?? 0, 'depth' => $depth, 'clicks' => $m['clicks'] ?? null, 'impressions' => $m['impressions'] ?? null, 'indexable' => $indexable, 'reason' => $reason];
        }
        usort($rows, match ($view) {
            self::UNDERLINKED => static fn(array $a, array $b): int => [$b['clicks'], $a['strength'], $a['url']] <=> [$a['clicks'], $b['strength'], $b['url']],
            default => static fn(array $a, array $b): int => [$b['strength'], $b['inbound'], $a['url']] <=> [$a['strength'], $a['inbound'], $b['url']],
        });
        $result['rows'] = array_slice($rows, 0, self::ROWS);

        return $result;
    }
}
