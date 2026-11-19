<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Rankings\TargetPageService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Rankings → Target pages (1.8.0).
 */
final readonly class TargetPageController
{
    public function __construct(
        private TargetPageService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $filter = $request->getQueryParams()['filter'] ?? 'all';

        return $this->responses->html($this->views->render('rankings/target-pages', [
            'project' => $project,
            ...$this->service->report(WebContext::user($request), $project, is_string($filter) ? $filter : 'all'),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $user = WebContext::user($request);
        $session = WebContext::session($request);
        $back = '/projects/' . $project->publicId . '/rankings/target-pages';
        $filter = is_string($body['filter'] ?? null) && $body['filter'] !== 'all' ? (string) $body['filter'] : null;
        if (($body['action'] ?? null) === 'adopt') {
            $count = $this->service->adoptCurrent($user, $project);
            $session->flash('status', sprintf('%d %s now have the page ranking today as their target.', $count, $count === 1 ? 'keyword' : 'keywords'));

            return $this->responses->redirect($back);
        }
        try {
            $this->service->set($user, $project, is_string($body['keyword'] ?? null) ? $body['keyword'] : '', is_string($body['url'] ?? null) ? $body['url'] : '');
            $session->flash('status', 'Target page saved.');
        } catch (ValidationException $e) {
            $session->flash('error', implode(' ', $e->errors()));
        }

        return $this->responses->redirect($back . ($filter !== null ? '?filter=' . rawurlencode($filter) : ''));
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
