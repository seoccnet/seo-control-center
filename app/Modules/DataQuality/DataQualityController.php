<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataQuality;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class DataQualityController
{
    public function __construct(private ProjectService $projects, private DataQualityService $quality, private ResponseFactory $responses, private ViewRenderer $views) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));

        return $this->responses->html($this->views->render('data-quality/index', ['project' => $project, 'report' => $this->quality->inspect($project)], $request))->withHeader('Cache-Control', 'no-store');
    }
}
