<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Indexing\IndexingService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class IndexingController
{
    public function __construct(private ProjectService $projects, private IndexingService $indexing, private ResponseFactory $responses, private ViewRenderer $views) {}
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $query = $request->getQueryParams();
        $text = static fn(string $key): string => is_string($query[$key] ?? null) ? $query[$key] : '';
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        return $this->responses->html($this->views->render('indexing/index', ['project' => $project, 'listing' => $this->indexing->listing($project, $text('q'), $text('status'), $page === false ? 1 : $page), 'selectedUrl' => mb_substr($text('url'), 0, 2048), 'canEdit' => $this->projects->can(WebContext::user($request), Permission::ProjectsEdit, $project)], $request))->withHeader('Cache-Control', 'no-store');
    }
    public function history(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $url = $request->getQueryParams()['url'] ?? '';
        return $this->responses->html($this->views->render('indexing/history', ['project' => $project, 'data' => $this->indexing->history($project, is_string($url) ? $url : ''), 'canEdit' => $this->projects->can(WebContext::user($request), Permission::ProjectsEdit, $project)], $request))->withHeader('Cache-Control', 'no-store');
    }
    public function recordRequest(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $body = WebContext::body($request);
        $text = static fn(string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
        $this->indexing->recordRequest(WebContext::user($request), $project, $text('url'), $text('channel'), $text('requested_on'), $text('evidence'), $text('note'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Indexing request recorded. It shows as indexed only once a Google check reports it.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/indexing/history?url=' . rawurlencode(trim($text('url'))));
    }
    public function request(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $body = WebContext::body($request);
        $watch = ($body['action'] ?? '') === 'watch' ? ($body['watch'] ?? '') === '1' : null;
        $this->indexing->request(WebContext::user($request), $project, is_string($body['url'] ?? null) ? $body['url'] : '', $watch, ($body['action'] ?? '') !== 'watch', ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', $watch === null ? 'Inspection queued. The saved result appears after cron runs.' : ($watch ? 'URL added to the weekly priority watchlist.' : 'Weekly checks stopped. Saved evidence remains available.'));
        return $this->responses->redirect('/projects/' . $project->publicId . '/indexing');
    }
}
