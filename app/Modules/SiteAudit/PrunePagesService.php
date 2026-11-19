<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Domain\Backlinks\PageBacklink;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\RowFilter;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\SiteAudit\Crawler\Pagination;

/**
 * Pages to prune (1.7.0): indexable pages of the latest completed audit
 * that Google showed in no search for up to a year of imported Search
 * Console data, with a suggestion each. Pages with stored backlinks are
 * redirect candidates, short pages merge candidates, the rest improve or
 * noindex candidates. Stored data only; SEOcc changes nothing on the site.
 */
final readonly class PrunePagesService
{
    public const int DAYS = 365;
    /** Less imported data than this says too little about a page. */
    public const int MIN_DAYS = 90;
    public const int ROWS = 1000;
    /** Search Console pages read; a site with more pages with impressions is only partly checked. */
    private const int READ = 25000;

    public const string REDIRECT = 'redirect';
    public const string MERGE = 'merge';
    public const string IMPROVE = 'improve';

    public function __construct(
        private SiteAuditRepository $audits,
        private SearchPerformanceReader $search,
        private ?ResearchRepository $research,
    ) {}

    /**
     * @return array{
     *     audited: bool, searchData: bool, enoughData: bool, partial: bool, range: ?DateRange,
     *     indexable: int, newPages: int, since: ?string, counts: array<string, int>, linkSample: bool,
     *     rows: list<array{url: string, action: string, words: ?int, inbound: int, backlinks: int, title: ?string}>
     * }
     */
    public function report(Project $project): array
    {
        $audit = $this->audits->latest($project->id, 'completed');
        $span = $this->search->dataSpan($project->id);
        $counts = [self::REDIRECT => 0, self::MERGE => 0, self::IMPROVE => 0];
        $result = ['audited' => $audit !== null, 'searchData' => $span !== null, 'enoughData' => false, 'partial' => false, 'range' => null, 'indexable' => 0, 'newPages' => 0, 'since' => null, 'counts' => $counts, 'linkSample' => false, 'rows' => []];
        if ($audit === null || $span === null) {
            return $result;
        }
        $range = DateRange::endingOn($span['last'], self::DAYS);
        if ($span['first'] > $range->fromString()) {
            $range = new DateRange($span['first'], $span['last']);
        }
        $result['range'] = $range;
        if ($range->days() < self::MIN_DAYS) {
            return $result;
        }
        $result['enoughData'] = true;

        // Every page with an impression in the window.
        $seen = [];
        for ($offset = 0; $offset < self::READ; $offset += 500) {
            $batch = $this->search->matching($project->id, PerformanceDimension::Page, $range, new RowFilter(sort: 'impressions'), 500, $offset);
            foreach ($batch['rows'] as $row) {
                $seen[TitleRewriteService::key($row->key)] = true;
            }
            if (count($batch['rows']) < 500) {
                break;
            }
            $result['partial'] = $offset + 500 >= self::READ && $batch['total'] > self::READ;
        }

        // Pages missing from the oldest kept audit are new: no searches yet says little about them.
        $oldest = null;
        foreach ($this->audits->history($project->id, 20) as $candidate) {
            if ($candidate->isCompleted() && $candidate->id !== $audit->id) {
                $oldest = $candidate;
            }
        }
        $earlier = null;
        if ($oldest !== null) {
            $earlier = [];
            foreach ($this->audits->allPages($oldest->id) as $page) {
                $earlier[TitleRewriteService::key($page->url)] = true;
            }
            $result['since'] = substr($oldest->finishedAt ?? $oldest->createdAt, 0, 10);
        }

        $backlinks = [];
        $links = $this->research?->find($project->id, 'bl_links');
        if ($links !== null && $links->fetchedAt !== null) {
            $result['linkSample'] = true;
            foreach ($links->items as $item) {
                $link = is_array($item) ? PageBacklink::fromArray($item) : null;
                if ($link !== null) {
                    $key = TitleRewriteService::key($link->targetUrl);
                    $backlinks[$key] = ($backlinks[$key] ?? 0) + 1;
                }
            }
        }

        $inbound = $this->audits->inboundCounts($audit->id);
        $startPath = rtrim((string) parse_url($audit->startUrl, PHP_URL_PATH), '/');
        $rows = [];
        foreach ($this->audits->allPages($audit->id) as $page) {
            if ($page->state !== 'done' || !$page->isHtml() || ($page->statusCode ?? 0) !== 200 || $page->noindex) {
                continue;
            }
            $key = TitleRewriteService::key($page->url);
            $canonical = $page->canonical !== null && $page->canonical !== '' ? TitleRewriteService::key($page->canonical) : $key;
            $path = rtrim((string) parse_url($page->url, PHP_URL_PATH), '/');
            // Canonicalised elsewhere, the start page and later pages of a listing are not candidates.
            if ($canonical !== $key || $path === $startPath || (Pagination::pageNumber($page->url) ?? 1) > 1) {
                continue;
            }
            ++$result['indexable'];
            if (isset($seen[$key])) {
                continue;
            }
            if ($earlier !== null && !isset($earlier[$key])) {
                ++$result['newPages'];
                continue;
            }
            $linked = $backlinks[$key] ?? 0;
            $action = match (true) {
                $linked > 0 => self::REDIRECT,
                $page->wordCount !== null && $page->wordCount < IssueDetector::THIN_WORDS => self::MERGE,
                default => self::IMPROVE,
            };
            ++$counts[$action];
            $rows[] = ['url' => $page->url, 'action' => $action, 'words' => $page->wordCount, 'inbound' => $inbound[$page->id] ?? 0, 'backlinks' => $linked, 'title' => $page->title];
        }
        $order = [self::MERGE => 0, self::IMPROVE => 1, self::REDIRECT => 2];
        usort($rows, static fn(array $a, array $b): int => [$order[$a['action']], $a['inbound'], $a['url']] <=> [$order[$b['action']], $b['inbound'], $b['url']]);

        return [...$result, 'counts' => $counts, 'rows' => array_slice($rows, 0, self::ROWS)];
    }
}
