<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Csv;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\SiteAudit\LinkStrengthService;
use SeoControlCenter\Modules\SiteAudit\OrphanPagesService;
use SeoControlCenter\Modules\SiteAudit\PrunePagesService;

/**
 * Project → Site audit → Orphan pages and Pages to prune (1.7.0). Read-only
 * reports from the latest audit and imported Search Console data.
 */
final readonly class PageCleanupController
{
    public const array ACTIONS = ['merge' => 'Merge or remove', 'improve' => 'Improve or noindex', 'redirect' => 'Redirect'];

    public function __construct(
        private OrphanPagesService $orphans,
        private PrunePagesService $prune,
        private LinkStrengthService $strength,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    /** Internal link strength (1.8.0). */
    public function strength(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $show = $request->getQueryParams()['show'] ?? '';

        return $this->responses->html($this->views->render('site-audit/link-strength', [
            'project' => $project,
            ...$this->strength->report($project, is_string($show) ? $show : ''),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function orphans(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));

        return $this->responses->html($this->views->render('site-audit/orphans', [
            'project' => $project,
            ...$this->orphans->report($project),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function prune(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $report = $this->prune->report($project);
        if (($request->getQueryParams()['format'] ?? null) === 'csv') {
            $rows = [['URL', 'Suggestion', 'Words', 'Internal links to it', 'Stored backlinks', 'Title']];
            foreach ($report['rows'] as $row) {
                $rows[] = [$row['url'], self::ACTIONS[$row['action']], $row['words'] ?? '', $row['inbound'], $row['backlinks'], $row['title'] ?? ''];
            }

            return $this->responses->csv($rows, Csv::fileName($project->name, 'pages-to-prune', $report['range']?->toString() ?? 'no-data'));
        }

        return $this->responses->html($this->views->render('site-audit/prune', [
            'project' => $project,
            ...$report,
        ], $request))->withHeader('Cache-Control', 'no-store');
    }
}
