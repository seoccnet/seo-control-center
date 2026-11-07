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
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Competitors\CompetitorService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Competitor comparison dashboard and per-competitor keyword comparison.
 */
final readonly class CompetitorsController
{
    public function __construct(
        private ProjectService $projects,
        private CompetitorService $competitors,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, $this->load($request), [], '', 200);
    }

    public function add(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $domain = is_string($body['domain'] ?? null) ? $body['domain'] : '';
        try {
            $competitor = $this->competitors->add(
                WebContext::user($request),
                $project,
                $domain,
                is_string($body['source'] ?? null) ? $body['source'] : 'manual',
                ClientInfo::fromRequest($request),
            );
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), $domain, 422);
        }
        WebContext::session($request)->flash('status', sprintf('%s added. Its positions are recorded with every rank check.', $competitor->domain));

        return $this->responses->redirect($this->path($project));
    }

    public function remove(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $this->competitors->remove(WebContext::user($request), $project, WebContext::routeParam($request, 'competitor'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Competitor removed.');

        return $this->responses->redirect($this->path($project));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $view = $request->getQueryParams()['view'] ?? 'all';

        return $this->responses->html($this->views->render('competitors/show', [
            'project' => $project,
            ...$this->competitors->detail($project, WebContext::routeParam($request, 'competitor'), is_string($view) ? $view : 'all'),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, string> $errors
     */
    private function page(ServerRequestInterface $request, Project $project, array $errors, string $domain, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('competitors/index', [
            'project' => $project,
            ...$this->competitors->overview(WebContext::user($request), $project),
            'errors' => $errors,
            'domain' => $domain,
            'maxCompetitors' => CompetitorService::MAX_PER_PROJECT,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(
            WebContext::user($request),
            WebContext::workspace($request)->workspace(),
            WebContext::routeParam($request, 'project'),
        );
    }

    private function path(Project $project): string
    {
        return '/projects/' . $project->publicId . '/competitors';
    }
}
