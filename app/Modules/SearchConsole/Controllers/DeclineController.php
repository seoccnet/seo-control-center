<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\SearchConsole\SlowDeclineService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Performance → Slow decline (1.8.0).
 */
final readonly class DeclineController
{
    public function __construct(
        private SlowDeclineService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));

        return $this->responses->html($this->views->render('performance/decline', [
            'project' => $project,
            ...$this->service->report($project),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }
}
