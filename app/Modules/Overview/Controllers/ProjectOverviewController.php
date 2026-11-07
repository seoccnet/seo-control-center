<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Overview\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Overview\ProjectOverview;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class ProjectOverviewController
{
    public function __construct(
        private ProjectService $projects,
        private ProjectOverview $overview,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private \SeoControlCenter\Modules\Annotations\AnnotationService $annotations,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $project = $this->projects->get($user, WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $range = $request->getQueryParams()['range'] ?? ProjectOverview::DEFAULT_RANGE;

        $data = $this->overview->build($user, $project, is_string($range) ? $range : ProjectOverview::DEFAULT_RANGE);
        $chartRange = $data['search']['range'] ?? $data['bing']['range'] ?? null;
        return $this->responses->html($this->views->render('projects/show', [
            'project' => $project,
            ...$data,
            'annotations' => $chartRange instanceof \SeoControlCenter\Domain\Shared\DateRange ? $this->annotations->context($project, $chartRange) : [],
        ], $request))->withHeader('Cache-Control', 'no-store');
    }
}
