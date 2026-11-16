<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\SiteHealth\SiteHealthService;

/** Site health monitoring (1.3.0). */
final readonly class SiteHealthController
{
    public function __construct(
        private ProjectService $projects,
        private SiteHealthService $health,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);

        return $this->responses->html($this->views->render('site-health/index', [
            'project' => $project,
            ...$this->health->overview($project),
            'canEdit' => $this->health->canEdit(WebContext::user($request), $project),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function settings(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $enabled = ((array) $request->getParsedBody())['enabled'] ?? null;
        $this->health->setEnabled(WebContext::user($request), $project, $enabled === '1', ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', $enabled === '1' ? 'Site health monitoring is on. The first checks run within 15 minutes.' : 'Site health monitoring is off for this project.');

        return $this->responses->redirect($this->base($project));
    }

    public function checkNow(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        try {
            $this->health->checkNow(WebContext::user($request), $project);
            WebContext::session($request)->flash('status', 'Checks queued. Reload in a minute to see the results.');
        } catch (HttpException $e) {
            if (!in_array($e->statusCode(), [422, 429], true)) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());
        }

        return $this->responses->redirect($this->base($project));
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }

    private function base(Project $project): string
    {
        return '/projects/' . $project->publicId . '/site-health';
    }
}
