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
use SeoControlCenter\Modules\Local\LocalService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Local: business and area, local keywords, Maps positions and
 * the Business Profile.
 */
final readonly class LocalController
{
    public function __construct(
        private LocalService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, $this->load($request), [], [], 200);
    }

    public function settings(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $old = array_map(static fn(mixed $v): string => is_string($v) ? $v : '', array_intersect_key($body, array_flip(['business_name', 'coordinates', 'zoom', 'language'])));
        try {
            $this->service->saveSettings(
                WebContext::user($request),
                $project,
                $old['business_name'] ?? '',
                $body['coordinates'] ?? null,
                $body['zoom'] ?? null,
                $body['language'] ?? null,
                ($body['weekly'] ?? null) === '1',
                ClientInfo::fromRequest($request),
            );
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), $old, 422);
        }
        WebContext::session($request)->flash('status', 'Local settings saved.');

        return $this->back($project);
    }

    public function addKeywords(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $text = WebContext::body($request)['keywords'] ?? '';
        try {
            $added = $this->service->addKeywords(WebContext::user($request), $project, is_string($text) ? $text : '');
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), ['keywords' => is_string($text) ? $text : ''], 422);
        }
        WebContext::session($request)->flash('status', sprintf('%d local keyword%s added.', $added, $added === 1 ? '' : 's'));

        return $this->back($project);
    }

    public function removeKeyword(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $keyword = WebContext::body($request)['keyword'] ?? null;
        $this->service->removeKeyword(WebContext::user($request), $project, is_string($keyword) ? $keyword : '');
        WebContext::session($request)->flash('status', 'Local keyword removed.');

        return $this->back($project);
    }

    public function check(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $this->service->check(WebContext::user($request), $project, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Local check queued. Results appear after the next cron run; this page updates itself.');

        return $this->back($project);
    }

    public function grid(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $keyword = is_string($body['grid_keyword'] ?? null) ? $body['grid_keyword'] : '';
        try {
            $this->service->grid(WebContext::user($request), $project, $keyword, $body['grid_size'] ?? null, $body['grid_spacing'] ?? null, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), ['grid_keyword' => $keyword], 422);
        }
        WebContext::session($request)->flash('status', 'Ranking grid queued. The points are checked with the next cron runs; this page updates itself.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/local#grid');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function page(ServerRequestInterface $request, Project $project, array $errors, array $old, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('local/index', [
            'project' => $project,
            ...$this->service->page(WebContext::user($request), $project),
            'errors' => $errors,
            'old' => $old,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }

    private function back(Project $project): ResponseInterface
    {
        return $this->responses->redirect('/projects/' . $project->publicId . '/local');
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
