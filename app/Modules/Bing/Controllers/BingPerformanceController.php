<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Csv;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Modules\Bing\BingDataRepository;
use SeoControlCenter\Modules\Bing\BingService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Bing performance for one project, read from imported data only: daily
 * clicks and impressions, top queries and pages from Bing's weekly buckets.
 */
final readonly class BingPerformanceController
{
    /** Bing keeps about six months, so the longer ranges are left out. */
    public const array RANGES = ['7d' => 7, '28d' => 28, '3m' => 91, '6m' => 182];
    private const string DEFAULT_RANGE = '28d';
    public const int EXPORT_ROWS = 5000;

    public function __construct(
        private ProjectService $projects,
        private BingService $service,
        private BingDataRepository $data,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private \SeoControlCenter\Modules\Annotations\AnnotationService $annotations,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $rangeKey = self::rangeKey($request);
        $window = $this->service->window($project, self::RANGES[$rangeKey]);
        $data = null;
        if ($window !== null) {
            $range = $window['range'];
            $byDate = [];
            foreach ($this->data->daily($project->id, $range) as $day) {
                $byDate[$day->date] = $day->metrics;
            }
            $data = [
                'range' => $range,
                'totals' => $window['totals'],
                'previous' => $window['previous'],
                'hasPrevious' => $window['hasPrevious'],
                'days' => array_map(static fn(string $d): array => [$d, $byDate[$d] ?? null], $range->eachDay()),
                'weeks' => $this->data->weeks($project->id, $range),
                'queries' => $this->data->top($project->id, PerformanceDimension::Query, $range, 25),
                'pages' => $this->data->top($project->id, PerformanceDimension::Page, $range, 25),
                'crawl' => $this->data->crawlStats($project->id, $range),
                'links' => $this->data->linkCounts($project->id, 25),
                'linkTotals' => $this->data->linkTotals($project->id),
            ];
        }

        return $this->responses->html($this->views->render('performance/bing', [
            'project' => $project,
            'link' => $this->service->projectLink($project),
            'rangeKey' => $rangeKey,
            'ranges' => self::RANGES,
            'data' => $data,
            'annotations' => $data === null ? [] : $this->annotations->context($project, $data['range']),
            'staleSince' => $window['staleSince'] ?? null,
            'canImport' => $this->projects->can(WebContext::user($request), Permission::IntegrationsManage, $project),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Top queries or pages of the selected range as CSV (?dimension=query|page&range=28d).
     */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $rangeKey = self::rangeKey($request);
        $dimension = ($request->getQueryParams()['dimension'] ?? 'query') === 'page' ? PerformanceDimension::Page : PerformanceDimension::Query;
        $window = $this->service->window($project, self::RANGES[$rangeKey]);
        $rows = [[$dimension === PerformanceDimension::Page ? 'Page' : 'Query', 'Clicks', 'Impressions', 'CTR (%)', 'Average position']];
        $end = 'no-data';
        if ($window !== null) {
            $end = $window['range']->toString();
            foreach ($this->data->top($project->id, $dimension, $window['range'], self::EXPORT_ROWS) as $row) {
                $rows[] = [$row->key, $row->metrics->clicks, $row->metrics->impressions, round($row->metrics->ctr() * 100, 2), round($row->metrics->position, 1)];
            }
        }
        $kind = 'bing-' . ($dimension === PerformanceDimension::Page ? 'pages' : 'queries') . '-' . $rangeKey;

        return $this->responses->csv($rows, Csv::fileName($project->name, $kind, $end));
    }

    public function import(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $queued = $this->service->requestImport(WebContext::user($request), $project);
        WebContext::session($request)->flash('status', $queued ? 'Bing import queued. It runs in the background with the next cron runs.' : 'Link a Bing site first.');

        return $this->responses->redirect('/projects/' . $project->publicId . ($queued ? '/performance/bing' : '/bing'));
    }

    private static function rangeKey(ServerRequestInterface $request): string
    {
        $key = $request->getQueryParams()['range'] ?? self::DEFAULT_RANGE;

        return is_string($key) && isset(self::RANGES[$key]) ? $key : self::DEFAULT_RANGE;
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
