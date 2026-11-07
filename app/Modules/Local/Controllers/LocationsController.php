<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Local\LocationService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class LocationsController
{
    public function __construct(private LocationService $service, private ProjectService $projects, private ResponseFactory $responses, private ViewRenderer $views) {}
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, $this->load($request));
    }
    public function add(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        try {
            $this->service->add(WebContext::user($request), $project, WebContext::body($request), ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), 422);
        }
        return $this->responses->redirect('/projects/' . $project->publicId . '/local/locations');
    }
    public function remove(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $this->service->remove(WebContext::user($request), $project, WebContext::routeParam($request, 'location'), ClientInfo::fromRequest($request));
        return $this->responses->redirect('/projects/' . $project->publicId . '/local/locations');
    }
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        try {
            $this->service->update(WebContext::user($request), $project, WebContext::routeParam($request, 'location'), WebContext::body($request), ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), 422);
        }
        return $this->responses->redirect('/projects/' . $project->publicId . '/local/locations');
    }
    public function check(ServerRequestInterface $request): ResponseInterface
    {
        return $this->request($request, 'check');
    }
    public function reviews(ServerRequestInterface $request): ResponseInterface
    {
        return $this->request($request, 'reviews');
    }
    public function grid(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        try {
            $this->service->grid(WebContext::user($request), $project, WebContext::routeParam($request, 'location'), WebContext::body($request), ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), 422);
        }
        WebContext::session($request)->flash('status', 'Branch grid queued. Each point uses the workspace provider budget; cron fills the grid.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/local/locations');
    }
    private function request(ServerRequestInterface $request, string $mode): ResponseInterface
    {
        $project = $this->load($request);
        $this->service->request(WebContext::user($request), $project, WebContext::routeParam($request, 'location'), $mode, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Local analysis queued. Review tasks can take up to 45 minutes; cron retrieves their results.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/local/locations');
    }
    /** @param array<string, string> $errors */
    private function page(ServerRequestInterface $request, Project $project, array $errors = [], int $status = 200): ResponseInterface
    {
        return $this->responses->html($this->views->render('local/locations', ['project' => $project, ...$this->service->page(WebContext::user($request), $project), 'errors' => $errors], $request), $status)->withHeader('Cache-Control', 'no-store');
    }
    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
