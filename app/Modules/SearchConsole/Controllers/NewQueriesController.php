<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\SearchConsole\NewQueriesService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Performance → New queries (1.6.0).
 */
final readonly class NewQueriesController
{
    public function __construct(
        private NewQueriesService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $days = $request->getQueryParams()['days'] ?? '28';

        return $this->responses->html($this->views->render('performance/new-queries', [
            'project' => $project,
            ...$this->service->report(WebContext::user($request), $project, is_string($days) && ctype_digit($days) ? (int) $days : 28),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }
}
