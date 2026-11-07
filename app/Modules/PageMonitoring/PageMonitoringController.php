<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class PageMonitoringController
{
    public function __construct(private ProjectService $projects, private PageMonitoringService $service, private PageWatchReader $data, private ResponseFactory $responses, private ViewRenderer $views, private ?\SeoControlCenter\Modules\Pages\PageService $pages = null) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $filters = $this->service->historyFilters($request->getQueryParams());
        if (($request->getQueryParams()['format'] ?? null) === 'csv') {
            $changeId = $request->getQueryParams()['change'] ?? '';
            if (!is_string($changeId)) {
                throw new \SeoControlCenter\Core\Http\Exception\HttpException(422, 'Choose one detected change or export the retained history.');
            }
            return $this->responses->csv($this->service->exportRows($project, $changeId, $filters['q'], $filters['kind']), \SeoControlCenter\Core\Http\Csv::fileName($project->name, 'page-changes', 'history'));
        }
        $total = $this->data->countHistory($project, $filters['q'], $filters['kind']);
        $pages = max(1, (int) ceil($total / 25));
        $number = filter_var($request->getQueryParams()['page'] ?? 1, FILTER_VALIDATE_INT);
        $page = max(1, min($pages, $number === false ? 1 : $number));
        $selected = $request->getQueryParams()['url'] ?? '';
        $changeId = $request->getQueryParams()['change'] ?? '';
        $selectedChange = is_string($changeId) && $changeId !== '' ? ($this->data->change($project, $changeId) ?? throw new NotFoundHttpException('Detected change is no longer available.')) : null;
        if ($selectedChange !== null) {
            $page = $pages = 1;
        }
        $watches = $this->data->watches($project);
        $canEdit = $this->projects->can(WebContext::user($request), Permission::ProjectsEdit, $project);
        $suggestions = [];
        if ($canEdit && $this->pages !== null) {
            // Pages with the most Google clicks that are not watched yet, to pick from.
            $watched = array_fill_keys(array_column($watches, 'url'), true);
            foreach ($this->pages->index($project, 1, '', 'google_clicks', 40)['rows'] as $row) {
                if (!isset($watched[$row->url]) && PageMonitoringService::validUrl($project, $row->url) && count($suggestions) < 15) {
                    $suggestions[] = ['url' => $row->url, 'clicks' => $row->google?->clicks];
                }
            }
        }
        return $this->responses->html($this->views->render('page-monitoring/index', ['project' => $project, 'watches' => $watches, 'limit' => $this->service->limit($project), 'canManageLimit' => $this->service->canManageLimit(WebContext::user($request), $project), 'suggestions' => $suggestions, 'changes' => $selectedChange === null ? $this->data->history($project, $filters['q'], $filters['kind'], $page) : [$selectedChange], 'filters' => $filters, 'total' => $total, 'selectedChange' => $selectedChange !== null, 'page' => $page, 'pages' => $pages, 'selectedUrl' => is_string($selected) ? mb_substr($selected, 0, 2048) : '', 'canEdit' => $this->projects->can(WebContext::user($request), Permission::ProjectsEdit, $project)], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function watch(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);
        $this->service->watch(WebContext::user($request), $project, is_string($body['url'] ?? null) ? trim($body['url']) : '', ($body['action'] ?? null) !== 'unwatch', ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Page watchlist saved. Cron checks new pages and repeats each watch weekly.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/page-monitoring');
    }

    /** Adds pasted URLs and pages picked from the suggestions. */
    public function bulk(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);
        $picked = is_array($body['pick'] ?? null) ? array_filter($body['pick'], is_string(...)) : [];
        $text = trim((is_string($body['urls'] ?? null) ? $body['urls'] : '') . "\n" . implode("\n", $picked));
        try {
            $r = $this->service->watchMany(WebContext::user($request), $project, $text, ClientInfo::fromRequest($request));
        } catch (\SeoControlCenter\Core\Http\Exception\HttpException $e) {
            if ($e->statusCode() !== 422) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());

            return $this->responses->redirect('/projects/' . $project->publicId . '/page-monitoring');
        }
        $message = sprintf('%d %s added; each is read now for its "before" snapshot.', $r['added'], $r['added'] === 1 ? 'page' : 'pages')
            . ($r['existing'] > 0 ? sprintf(' %d already watched.', $r['existing']) : '')
            . ($r['over_limit'] > 0 ? sprintf(' %d not added: the limit is %d pages per project.', $r['over_limit'], $r['limit']) : '')
            . ($r['invalid'] !== [] ? ' Not a page on this project’s domain: ' . implode(', ', array_slice($r['invalid'], 0, 5)) . (count($r['invalid']) > 5 ? ' …' : '') . '.' : '');
        WebContext::session($request)->flash('status', $message);

        return $this->responses->redirect('/projects/' . $project->publicId . '/page-monitoring');
    }

    public function limit(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $value = WebContext::body($request)['limit'] ?? '';
        try {
            $this->service->setLimit(WebContext::user($request), $project, is_string($value) && ctype_digit($value) ? (int) $value : 0, ClientInfo::fromRequest($request));
            WebContext::session($request)->flash('status', 'Page watch limit saved for every project of this workspace.');
        } catch (\SeoControlCenter\Core\Http\Exception\HttpException $e) {
            if ($e->statusCode() !== 422) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());
        }

        return $this->responses->redirect('/projects/' . $project->publicId . '/page-monitoring');
    }

    public function annotate(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $date = WebContext::body($request)['date'] ?? '';
        try {
            $id = $this->service->annotate(WebContext::user($request), $project, WebContext::routeParam($request, 'change'), is_string($date) ? $date : '', ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            WebContext::session($request)->flash('status', implode(' ', $e->errors()));
            return $this->responses->redirect('/projects/' . $project->publicId . '/page-monitoring');
        }
        return $this->responses->redirect('/projects/' . $project->publicId . '/annotations/' . $id . '/impact');
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
