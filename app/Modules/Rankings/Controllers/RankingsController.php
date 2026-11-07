<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Csv;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\PositionFilter;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Rankings\RankingService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Rankings dashboard and settings. Reads stored snapshots only; checks run
 * in background jobs.
 */
final readonly class RankingsController
{
    public function __construct(
        private ProjectService $projects,
        private RankingService $rankings,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private Clock $clock,
        private \SeoControlCenter\Modules\Annotations\AnnotationService $annotations,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $page = $query['page'] ?? '1';
        $filter = PositionFilter::tryFrom(is_string($query['filter'] ?? null) ? $query['filter'] : '');

        return $this->page($request, $this->load($request), is_string($page) && ctype_digit($page) ? (int) $page : 1, [], 200, $filter);
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $filter = PositionFilter::tryFrom(is_string($request->getQueryParams()['filter'] ?? null) ? $request->getQueryParams()['filter'] : '');

        return $this->responses->csv($this->rankings->exportRows($project, $filter), Csv::fileName($project->name, 'rankings', $this->clock->now()->format('Y-m-d')));
    }

    public function keyword(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);

        $data = $this->rankings->keyword($project, WebContext::routeParam($request, 'keyword'));
        $checks = $data['checks'];
        $range = $checks === [] ? null : new \SeoControlCenter\Domain\Shared\DateRange($checks[0]->date, $checks[count($checks) - 1]->date);
        return $this->responses->html($this->views->render('rankings/keyword', [
            'project' => $project,
            ...$data,
            'annotations' => $range === null ? [] : $this->annotations->context($project, $range),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function settings(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        try {
            $settings = $this->rankings->saveSettings(WebContext::user($request), $project, WebContext::body($request), ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $project, 1, $e->errors(), 422);
        }
        WebContext::session($request)->flash('status', $settings->enabled
            ? 'Rank tracking settings saved. Checks run in the background.'
            : 'Rank tracking is switched off. Existing history is kept.');

        return $this->responses->redirect($this->path($project));
    }

    public function check(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        try {
            $this->rankings->checkNow(WebContext::user($request), $project);
            WebContext::session($request)->flash('status', 'Rank check queued. Keywords already checked today are not checked again.');
        } catch (HttpException $e) {
            if ($e->statusCode() !== 409) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());
        }

        return $this->responses->redirect($this->path($project));
    }

    /**
     * @param array<string, string> $errors
     */
    private function page(ServerRequestInterface $request, Project $project, int $page, array $errors, int $status, ?PositionFilter $filter = null): ResponseInterface
    {
        return $this->responses->html($this->views->render('rankings/show', [
            'project' => $project,
            ...$this->rankings->overview(WebContext::user($request), $project, $page, $filter),
            'errors' => $errors,
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
        return '/projects/' . $project->publicId . '/rankings';
    }
}
