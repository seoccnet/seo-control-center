<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Backlinks\BacklinksService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Backlinks.
 */
final readonly class BacklinksController
{
    private const array SORTS = ['domain', 'rank', 'backlinks', 'spam', 'first_seen', 'lost', 'targets'];

    public function __construct(
        private BacklinksService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $query = $request->getQueryParams();
        $sort = is_string($query['sort'] ?? null) && in_array($query['sort'], self::SORTS, true) ? $query['sort'] : 'rank';
        $dir = ($query['dir'] ?? null) === 'asc' ? 'asc' : (($query['dir'] ?? null) === 'desc' ? 'desc' : (in_array($sort, ['domain', 'spam'], true) ? 'asc' : 'desc'));

        return $this->responses->html($this->views->render('backlinks/index', [
            'project' => $project,
            ...$this->service->page(WebContext::user($request), $project),
            'sort' => $sort,
            'dir' => $dir,
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function fetch(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $kind = WebContext::body($request)['kind'] ?? '';
        try {
            $this->service->request(WebContext::user($request), $project, is_string($kind) ? $kind : '', ClientInfo::fromRequest($request), \SeoControlCenter\Modules\Shared\Http\RepeatConfirmation::requested($request));
            WebContext::session($request)->flash('status', 'Lookup queued. The result appears here after the next cron run; this page updates itself.');
        } catch (\SeoControlCenter\Modules\Research\RepeatedLookup $e) {
            \SeoControlCenter\Modules\Shared\Http\RepeatConfirmation::flash($request, $e->getMessage());
        }

        return $this->responses->redirect('/projects/' . $project->publicId . '/backlinks#' . (is_string($kind) ? $kind : ''));
    }

    public function monthly(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $monthly = (WebContext::body($request)['monthly'] ?? null) === '1';
        $this->service->setMonthly(WebContext::user($request), $project, $monthly, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', $monthly ? 'The link profiles are checked every month from now on.' : 'The monthly check is off.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/backlinks');
    }

    public function monitoring(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $this->service->setLostMonitoring(WebContext::user($request), $project, (WebContext::body($request)['monitor_lost'] ?? null) === '1', ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Lost-link monitoring settings saved.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/backlinks');
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
