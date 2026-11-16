<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Competitors\CompetitorWatchService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Competitors → New pages (1.6.0).
 */
final readonly class CompetitorWatchController
{
    public function __construct(
        private CompetitorWatchService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $matched = ($request->getQueryParams()['matched'] ?? null) === '1';

        return $this->responses->html($this->views->render('competitors/watch', [
            'project' => $project,
            'matchedOnly' => $matched,
            ...$this->service->page(WebContext::user($request), $project, $matched),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function toggle(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $enabled = (WebContext::body($request)['enabled'] ?? null) === '1';
        $this->service->setEnabled(WebContext::user($request), $project, $enabled, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', $enabled ? 'Watching competitors\' sitemaps. The first read is the baseline; new pages appear from the next day on.' : 'Competitor sitemaps are no longer read.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/competitors/new-pages');
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
