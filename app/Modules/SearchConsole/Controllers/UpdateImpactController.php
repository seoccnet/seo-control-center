<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\SearchConsole\UpdateImpactService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Performance → Google updates (1.6.0).
 */
final readonly class UpdateImpactController
{
    public function __construct(
        private UpdateImpactService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $slug = $request->getQueryParams()['update'] ?? '';

        return $this->responses->html($this->views->render('performance/updates', [
            'project' => $project,
            ...$this->service->report($project, is_string($slug) ? $slug : ''),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }
}
