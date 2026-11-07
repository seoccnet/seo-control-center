<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Clusters;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Keywords → Topics & owners.
 */
final readonly class ClustersController
{
    public function __construct(private ProjectService $projects, private ClusterService $clusters, private ResponseFactory $responses, private ViewRenderer $views) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $query = $request->getQueryParams();
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $filters = ['status' => is_string($query['status'] ?? null) ? $query['status'] : '', 'q' => is_string($query['q'] ?? null) ? $query['q'] : '', 'show' => ($query['show'] ?? null) === 'all' ? 'all' : ''];

        return $this->responses->html($this->views->render('clusters/index', [
            'project' => $project,
            'listing' => $this->clusters->listing($project, $filters, $page === false ? 1 : $page),
            'filters' => $filters,
            'canEdit' => $this->projects->can(WebContext::user($request), Permission::ProjectsEdit, $project),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function owner(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);
        $id = WebContext::routeParam($request, 'cluster');
        $raw = $body['revision'] ?? null;
        $revision = is_string($raw) && ctype_digit($raw) && strlen($raw) <= 9 ? (int) $raw : 0;
        $url = ($body['action'] ?? null) === 'clear' ? null : (is_string($body['url'] ?? null) ? $body['url'] : '');
        try {
            $cluster = $this->clusters->setOwner(WebContext::user($request), $project, $id, $url, $revision, ClientInfo::fromRequest($request));
            WebContext::session($request)->flash('status', $cluster->manualOwnerUrl === null ? 'Owner set back to the page Search Console points to.' : 'Owner saved. It stays when the analysis runs again.');
        } catch (ValidationException $e) {
            WebContext::session($request)->flash('error', implode(' ', $e->errors()));
        } catch (HttpException $e) {
            if ($e->statusCode() !== 409) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());
        }

        return $this->responses->redirect('/projects/' . $project->publicId . '/keywords/topics#cluster-' . $id);
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
