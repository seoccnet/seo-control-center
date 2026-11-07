<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Projects\Controllers;

use DateTimeZone;
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

final readonly class ProjectsController
{
    public function __construct(
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $workspace = WebContext::workspace($request)->workspace();

        return $this->render($request, 'projects/index', [
            'projects' => $this->projects->visibleProjects($user, $workspace),
            'canCreate' => $this->projects->canCreate($user, $workspace),
        ]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $workspace = WebContext::workspace($request)->workspace();
        if (!$this->projects->canCreate($user, $workspace)) {
            throw new HttpException(403, 'You do not have permission to create projects.');
        }

        return $this->form($request, null, ['name' => '', 'primary_domain' => '', 'timezone' => 'UTC'], []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $workspace = WebContext::workspace($request)->workspace();
        $body = WebContext::body($request);

        try {
            $project = $this->projects->create($user, $workspace, $body, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->form($request, null, $body, $e->errors(), 422);
        }
        WebContext::session($request)->flash('status', 'Project created.');

        return $this->responses->redirect('/projects/' . $project->publicId);
    }

    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $project = $this->load($request);
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You do not have permission to edit this project.');
        }

        return $this->form($request, $project, [
            'name' => $project->name,
            'primary_domain' => $project->primaryDomain,
            'timezone' => $project->timezone,
        ], []);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $project = $this->load($request);
        $body = WebContext::body($request);

        try {
            $this->projects->update($user, $project, $body, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->form($request, $project, $body, $e->errors(), 422);
        }
        WebContext::session($request)->flash('status', 'Project saved.');

        return $this->responses->redirect('/projects/' . $project->publicId);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $project = $this->load($request);
        $body = WebContext::body($request);

        if (($body['confirm_name'] ?? null) !== $project->name) {
            WebContext::session($request)->flash('error', 'Type the project name exactly to confirm deletion.');

            return $this->responses->redirect('/projects/' . $project->publicId);
        }

        $this->projects->delete($user, $project, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', sprintf('Project "%s" deleted.', $project->name));

        return $this->responses->redirect('/projects');
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(
            WebContext::user($request),
            WebContext::workspace($request)->workspace(),
            WebContext::routeParam($request, 'project'),
        );
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<string, string> $errors
     */
    private function form(ServerRequestInterface $request, ?Project $project, array $values, array $errors, int $status = 200): ResponseInterface
    {
        return $this->render($request, 'projects/form', [
            'project' => $project,
            'values' => $values,
            'errors' => $errors,
            'timezones' => DateTimeZone::listIdentifiers(),
            'canDelete' => $project !== null && $this->projects->can(WebContext::user($request), Permission::ProjectsDelete, $project),
        ], $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(ServerRequestInterface $request, string $template, array $data, int $status = 200): ResponseInterface
    {
        return $this->responses->html($this->views->render($template, $data, $request), $status);
    }
}
