<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Csv;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\ImportScheduler;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\SearchConsole\SearchType;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\SearchConsole\SearchConsoleService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Search performance dashboard for one project, read from imported data only
 * (never a live Google call on page load).
 */
final readonly class PerformanceController
{
    /** Range keys offered in the filter row => days. */
    public const array RANGES = ['7d' => 7, '28d' => 28, '3m' => 91, '6m' => 182, '12m' => 365, '16m' => 486];
    private const string DEFAULT_RANGE = '28d';
    /** Rows per CSV export (the stored data holds at most this many per dimension and day). */
    public const int EXPORT_ROWS = 5000;

    public function __construct(
        private ProjectService $projects,
        private SearchConsoleService $searchConsole,
        private SearchPerformanceReader $reader,
        private SearchPerformanceStore $store,
        private ImportScheduler $imports,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private FeatureManager $features,
        private \SeoControlCenter\Modules\Annotations\AnnotationService $annotations,
        private SearchConsoleExtras $extras,
        private Clock $clock,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $rangeKey = $request->getQueryParams()['range'] ?? self::DEFAULT_RANGE;
        if (!is_string($rangeKey) || !isset(self::RANGES[$rangeKey])) {
            $rangeKey = self::DEFAULT_RANGE;
        }

        $span = $this->reader->dataSpan($project->id);
        $data = null;
        if ($span !== null) {
            // Anchor on the newest imported day: Google's data lags 2-3 days behind today.
            $range = DateRange::endingOn($span['last'], self::RANGES[$rangeKey]);
            $previous = $range->previous();
            $byDate = [];
            foreach ($this->reader->daily($project->id, $range) as $day) {
                $byDate[$day->date] = $day->metrics;
            }
            $data = [
                'range' => $range,
                'previousRange' => $previous,
                'totals' => $this->reader->totals($project->id, $range),
                'previous' => $this->reader->totals($project->id, $previous),
                'hasPrevious' => $span['first'] <= $previous->toString(),
                'days' => array_map(static fn(string $d): array => [$d, $byDate[$d] ?? null], $range->eachDay()),
                'queries' => $this->reader->top($project->id, PerformanceDimension::Query, $range, 25),
                'pages' => $this->reader->top($project->id, PerformanceDimension::Page, $range, 25),
                'devices' => $this->reader->top($project->id, PerformanceDimension::Device, $range, 5),
                'countries' => $this->reader->top($project->id, PerformanceDimension::Country, $range, 10),
                'fresh' => $this->extras->freshDays($project->id),
                'today' => $this->clock->now()->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('Y-m-d'),
                'surfaces' => $this->surfaces($project->id, $range, $previous),
            ];
        }

        return $this->responses->html($this->views->render('performance/show', [
            'project' => $project,
            'link' => $this->searchConsole->projectLink($project),
            'sync' => $this->store->syncState($project->id),
            'pendingDays' => $this->store->countPendingDays($project->id),
            'span' => $span,
            'rangeKey' => $rangeKey,
            'ranges' => self::RANGES,
            'data' => $data,
            'annotations' => $data === null ? [] : $this->annotations->context($project, $data['range']),
            'canImport' => $this->projects->can(WebContext::user($request), Permission::IntegrationsManage, $project),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Top queries or pages of the selected range as CSV (?dimension=query|page&range=28d).
     */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $query = $request->getQueryParams();
        $rangeKey = is_string($query['range'] ?? null) && isset(self::RANGES[$query['range']]) ? $query['range'] : self::DEFAULT_RANGE;
        $dimension = ($query['dimension'] ?? 'query') === 'page' ? PerformanceDimension::Page : PerformanceDimension::Query;

        $span = $this->reader->dataSpan($project->id);
        $label = $dimension === PerformanceDimension::Page ? 'Page' : 'Query';
        $rows = [[$label, 'Clicks', 'Impressions', 'CTR (%)', 'Average position']];
        $end = 'no-data';
        if ($span !== null) {
            $range = DateRange::endingOn($span['last'], self::RANGES[$rangeKey]);
            $end = $span['last'];
            foreach ($this->reader->top($project->id, $dimension, $range, self::EXPORT_ROWS) as $row) {
                $rows[] = [$row->key, $row->metrics->clicks, $row->metrics->impressions, round($row->metrics->ctr() * 100, 2), round($row->metrics->position, 1)];
            }
        }
        $kind = ($dimension === PerformanceDimension::Page ? 'pages' : 'queries') . '-' . $rangeKey;

        return $this->responses->csv($rows, Csv::fileName($project->name, $kind, $end));
    }

    /**
     * "Import now": queues an import (collapses with any request from the last few minutes).
     */
    public function import(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        if (!$this->projects->can(WebContext::user($request), Permission::IntegrationsManage, $project)) {
            throw new HttpException(403, 'You cannot start imports for this project.');
        }
        $this->features->require(Feature::SearchConsole);
        if ($this->searchConsole->projectLink($project) === null) {
            WebContext::session($request)->flash('status', 'Link a Search Console property first.');

            return $this->responses->redirect('/projects/' . $project->publicId . '/search-console');
        }
        $this->imports->requestImport($project->id, $project->workspaceId);
        WebContext::session($request)->flash('status', 'Import queued. It runs in the background with the next cron runs.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/performance');
    }

    /**
     * Other Google surfaces with impressions in the range or the previous one.
     *
     * @return list<array{type: SearchType, totals: Metrics, previous: Metrics}>
     */
    private function surfaces(int $projectId, DateRange $range, DateRange $previous): array
    {
        $current = $this->extras->searchTypeTotals($projectId, $range);
        $before = $this->extras->searchTypeTotals($projectId, $previous);
        $surfaces = [];
        foreach (SearchType::cases() as $type) {
            $totals = $current[$type->value] ?? Metrics::zero();
            $prior = $before[$type->value] ?? Metrics::zero();
            if ($totals->impressions > 0 || $prior->impressions > 0) {
                $surfaces[] = ['type' => $type, 'totals' => $totals, 'previous' => $prior];
            }
        }

        return $surfaces;
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(
            WebContext::user($request),
            WebContext::workspace($request)->workspace(),
            WebContext::routeParam($request, 'project'),
        );
    }
}
