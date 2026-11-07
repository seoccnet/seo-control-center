<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Csv;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Pages\PageExportService;
use SeoControlCenter\Modules\Pages\PageService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class PagesController
{
    public function __construct(private ProjectService $projects, private PageService $pages, private PageExportService $exports, private ResponseFactory $responses, private ViewRenderer $views) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $query = $request->getQueryParams();
        if (($query['format'] ?? null) === 'csv' && ($query['scope'] ?? null) === 'all') {
            $export = $this->pages->export($project, is_string($query['q'] ?? null) ? $query['q'] : '', is_string($query['sort'] ?? null) ? $query['sort'] : 'url');
            return $this->responses->csv($export['rows'], Csv::fileName($project->name, 'pages-all', $export['range']->toString()));
        }
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $perPage = filter_var($query['per_page'] ?? 50, FILTER_VALIDATE_INT);
        $data = $this->pages->index(
            $project,
            $page !== false ? max(1, min(100000, $page)) : 1,
            is_string($query['q'] ?? null) ? $query['q'] : '',
            is_string($query['sort'] ?? null) ? $query['sort'] : 'url',
            $perPage !== false ? $perPage : 50
        );
        if (($query['format'] ?? null) === 'csv') {
            return $this->responses->csv($this->pages->exportRows($data['rows'], $data['range']), Csv::fileName($project->name, 'pages-page-' . $data['page'], $data['range']->toString()));
        }
        return $this->responses->html($this->views->render('pages/index', [
            'project' => $project, ...$data,
            'exports' => $this->exports->recent($project),
            'directExportLimit' => PageService::DIRECT_EXPORT_LIMIT, 'maxExportUrls' => PageExportService::MAX_URLS,
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $url = $request->getQueryParams()['url'] ?? '';
        return $this->responses->html($this->views->render('pages/show', ['project' => $project, 'canCheckVitals' => $this->projects->can(WebContext::user($request), \SeoControlCenter\Core\Authorization\Permission::ProjectsEdit, $project), 'details' => $this->pages->details($project, is_string($url) ? $url : '')], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function requestExport(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $body = (array) $request->getParsedBody();
        $search = is_string($body['q'] ?? null) ? $body['q'] : '';
        $sort = is_string($body['sort'] ?? null) ? $body['sort'] : 'url';
        $back = '/projects/' . $project->publicId . '/pages?' . http_build_query(['q' => PageService::search($search), 'sort' => PageService::sort($sort)]) . '#page-exports';
        try {
            $result = $this->exports->request(WebContext::user($request), $project, $search, $sort, ClientInfo::fromRequest($request));
        } catch (HttpException $e) {
            if (!in_array($e->statusCode(), [422, 429], true)) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());

            return $this->responses->redirect($back);
        }
        WebContext::session($request)->flash('status', $result['created']
            ? sprintf('CSV export of %s URLs queued. Background tasks prepare it; download it below when it is ready.', number_format($result['export']->totalUrls))
            : 'An export for this project is already being prepared. Download it below when it is ready, then prepare the next one.');

        return $this->responses->redirect($back);
    }

    public function downloadExport(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $file = $this->exports->download($project, WebContext::routeParam($request, 'export'));

        return $this->responses->download($file['path'], $file['fileName'], 'text/csv; charset=utf-8')->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
