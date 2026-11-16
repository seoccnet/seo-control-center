<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Backlinks\LinkReclamationService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Backlinks → Links to missing pages (1.5.0).
 */
final readonly class LinkReclamationController
{
    public function __construct(
        private LinkReclamationService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);

        return $this->responses->html($this->views->render('backlinks/reclaim', [
            'project' => $project,
            ...$this->service->page(WebContext::user($request), $project),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function check(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        try {
            $this->service->request(WebContext::user($request), $project, ClientInfo::fromRequest($request));
            WebContext::session($request)->flash('status', 'Check queued. The linked pages are checked in the background, one request each.');
        } catch (HttpException $e) {
            if ($e->statusCode() !== 409) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());
        }

        return $this->responses->redirect('/projects/' . $project->publicId . '/backlinks/reclaim');
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
