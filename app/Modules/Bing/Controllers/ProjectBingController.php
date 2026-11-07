<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Bing\BingService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Linking a project to a Bing Webmaster Tools site.
 */
final readonly class ProjectBingController
{
    public function __construct(
        private BingService $service,
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
        $site = WebContext::body($request)['site'] ?? '';
        try {
            $this->service->linkProject(WebContext::user($request), $project, is_string($site) ? $site : '', ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors()['site'] ?? 'Choose a site.', 422);
        }
        WebContext::session($request)->flash('status', 'Bing site linked. The first import runs in the background with the next cron runs.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/bing');
    }

    public function unlink(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $this->service->unlinkProject(WebContext::user($request), $project, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Bing site unlinked. Imported data was kept.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/bing');
    }

    private function page(ServerRequestInterface $request, Project $project, ?string $error, int $status): ResponseInterface
    {
        $canManage = $this->projects->can(WebContext::user($request), Permission::IntegrationsManage, $project);

        return $this->responses->html($this->views->render('integrations/project-bing', [
            'project' => $project,
            'link' => $this->service->projectLink($project),
            'sites' => $canManage ? $this->service->linkableSites($project) : [],
            'canManage' => $canManage,
            'error' => $error,
        ], $request), $status);
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
