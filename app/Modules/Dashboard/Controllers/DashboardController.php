<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Dashboard\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Workspaces\CurrentWorkspace;
use SeoControlCenter\Modules\Overview\ProjectOverview;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class DashboardController
{
    private const int CARDS = 24;

    public function __construct(
        private ProjectService $projects,
        private ProjectOverview $overview,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $context = $request->getAttribute(RequestAttribute::WORKSPACE);

        $projects = [];
        $canCreate = false;
        if ($context instanceof CurrentWorkspace) {
            $projects = $this->projects->visibleProjects($user, $context->workspace());
            $canCreate = $this->projects->canCreate($user, $context->workspace());
        }

        return $this->responses->html($this->views->render('dashboard/index', [
            'user' => $user,
            'context' => $context instanceof CurrentWorkspace ? $context : null,
            'projects' => $projects,
            'canCreate' => $canCreate,
            // Cards for the first projects; each summary is a handful of indexed queries.
            'summaries' => array_map(fn(Project $p): array => $this->overview->summary($user, $p), array_slice($projects, 0, self::CARDS)),
        ], $request));
    }
}
