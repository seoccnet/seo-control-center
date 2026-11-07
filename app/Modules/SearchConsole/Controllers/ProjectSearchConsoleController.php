<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\SearchConsole\SearchConsoleService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Linking a project to a Search Console property.
 */
final readonly class ProjectSearchConsoleController
{
    public function __construct(
        private SearchConsoleService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, $this->load($request), null, 200);
    }

    public function link(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $choice = WebContext::body($request)['property'] ?? '';
        // Option values are "<connection public id>|<site url>".
        [$connectionId, $siteUrl] = array_pad(explode('|', is_string($choice) ? $choice : '', 2), 2, '');
        try {
            $this->service->linkProject(WebContext::user($request), $project, $connectionId, $siteUrl, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors()['property'] ?? 'Choose a property.', 422);
        }
        WebContext::session($request)->flash('status', 'Search Console property linked.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/search-console');
    }

    public function unlink(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $this->service->unlinkProject(WebContext::user($request), $project, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Search Console property unlinked. Imported data was kept.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/search-console');
    }

    private function page(ServerRequestInterface $request, Project $project, ?string $error, int $status): ResponseInterface
    {
        $user = WebContext::user($request);
        $workspace = WebContext::workspace($request)->workspace();
        $canManage = $this->projects->can($user, Permission::IntegrationsManage, $project);

        return $this->responses->html($this->views->render('integrations/project-link', [
            'project' => $project,
            'link' => $this->service->projectLink($project),
            'options' => $canManage ? $this->service->linkableProperties($workspace) : [],
            'canManage' => $canManage,
            'error' => $error,
        ], $request), $status);
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
