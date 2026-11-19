<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Competitors\ShareOfVoiceService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Competitors → Share of voice (1.8.0).
 */
final readonly class ShareOfVoiceController
{
    public function __construct(
        private ShareOfVoiceService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));

        return $this->responses->html($this->views->render('competitors/share-of-voice', [
            'project' => $project,
            ...$this->service->report($project),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }
}
