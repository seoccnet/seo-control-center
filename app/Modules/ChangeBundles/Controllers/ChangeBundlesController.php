<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ChangeBundles\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\ChangeBundles\ChangeBundleService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/** Manage → Change bundles (ADR-042, 0.40.0). */
final readonly class ChangeBundlesController
{
    public function __construct(private ProjectService $projects, private ChangeBundleService $bundles, private ResponseFactory $responses, private ViewRenderer $views, private ?\SeoControlCenter\Modules\ChangeBundles\BundleDrafts $drafts = null) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $from = $request->getQueryParams()['opportunity'] ?? null;
        $draft = is_string($from) && \SeoControlCenter\Core\Support\Uuid::isValid($from) ? $this->drafts?->fromOpportunity($project, $from) : null;

        return $this->responses->html($this->views->render('change-bundles/index', ['project' => $project, 'bundles' => $this->bundles->list($project), 'canEdit' => $this->canEdit($request, $project), 'draft' => $draft], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);

        return $this->responses->html($this->views->render('change-bundles/show', ['project' => $project, ...$this->bundles->show($project, WebContext::routeParam($request, 'bundle')), 'canEdit' => $this->canEdit($request, $project)], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $id = $this->bundles->create(WebContext::user($request), $project, WebContext::body($request), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Draft saved. Capture the live baseline next.');

        return $this->redirect($project, $id);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, fn(Project $project, string $id, array $body) => $this->bundles->update(WebContext::user($request), $project, $id, $body, ClientInfo::fromRequest($request)), 'Bundle saved.');
    }

    public function check(ServerRequestInterface $request): ResponseInterface
    {
        $purpose = WebContext::body($request)['purpose'] ?? '';

        return $this->act($request, fn(Project $project, string $id, array $body) => $this->bundles->queueCheck(WebContext::user($request), $project, $id, is_string($purpose) ? $purpose : '', (int) filter_var($body['version'] ?? -1, FILTER_VALIDATE_INT, ['options' => ['default' => -1]]), ClientInfo::fromRequest($request)), 'Live check queued. The result appears after cron runs.');
    }

    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, fn(Project $project, string $id, array $body) => $this->bundles->approve(WebContext::user($request), $project, $id, (int) filter_var($body['version'] ?? -1, FILTER_VALIDATE_INT, ['options' => ['default' => -1]]), ClientInfo::fromRequest($request)), 'Bundle approved. Check the live page before you deploy.');
    }

    public function deploy(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, fn(Project $project, string $id, array $body) => $this->bundles->recordDeployment(WebContext::user($request), $project, $id, $body, ClientInfo::fromRequest($request)), 'Deployment recorded and added to the change timeline. Verify it once the page is live.');
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);
        $this->bundles->delete(WebContext::user($request), $project, WebContext::routeParam($request, 'bundle'), (int) filter_var($body['version'] ?? -1, FILTER_VALIDATE_INT, ['options' => ['default' => -1]]), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Bundle deleted.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/change-bundles');
    }

    public function rollback(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, fn(Project $project, string $id, array $body) => $this->bundles->rollback(WebContext::user($request), $project, $id, $body, ClientInfo::fromRequest($request)), 'Rollback recorded.');
    }

    /** @param \Closure(Project, string, array<string, mixed>): void $action */
    private function act(ServerRequestInterface $request, \Closure $action, string $message): ResponseInterface
    {
        $project = $this->project($request);
        $id = WebContext::routeParam($request, 'bundle');
        $action($project, $id, WebContext::body($request));
        WebContext::session($request)->flash('status', $message);

        return $this->redirect($project, $id);
    }

    private function redirect(Project $project, string $id): ResponseInterface
    {
        return $this->responses->redirect('/projects/' . $project->publicId . '/change-bundles/' . $id);
    }

    private function canEdit(ServerRequestInterface $request, Project $project): bool
    {
        return $this->projects->can(WebContext::user($request), Permission::ProjectsEdit, $project);
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
