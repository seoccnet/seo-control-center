<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Opportunities\BusinessProfileService;
use SeoControlCenter\Modules\Research\ResearchService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Opportunities → Business profile: what the business sells and wants
 * from search, keyword ratings and the on-request competitor-strength
 * check of the stored results pages.
 */
final readonly class BusinessProfileController
{
    public function __construct(
        private ProjectService $projects,
        private BusinessProfileService $profiles,
        private ResearchService $research,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, $this->load($request), [], null, 200);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        try {
            $this->profiles->save(WebContext::user($request), $project, $body, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), $body, 422);
        }
        WebContext::session($request)->flash('status', 'Business profile saved. It applies at the next analysis; choose Analyze now on the opportunities page to apply it immediately.');

        return $this->responses->redirect($this->path($project) . '/business');
    }

    public function clear(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $this->profiles->clear(WebContext::user($request), $project, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Business profile removed. Findings fall back to the generic intent weight at the next analysis.');

        return $this->responses->redirect($this->path($project) . '/business');
    }

    public function rate(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $back = is_string($body['back'] ?? null) && str_starts_with($body['back'], '?') ? $body['back'] : '';
        try {
            $score = $this->profiles->rate(WebContext::user($request), $project, is_string($body['keyword'] ?? null) ? $body['keyword'] : '', is_string($body['score'] ?? null) ? $body['score'] : '', ClientInfo::fromRequest($request));
            WebContext::session($request)->flash('status', $score === null ? 'Business potential set back to automatic. It applies at the next analysis.' : sprintf('Business potential set to %d/3. It applies at the next analysis; choose Analyze now to apply it immediately.', $score));
        } catch (ValidationException $e) {
            WebContext::session($request)->flash('error', implode(' ', $e->errors()));
        }

        return $this->responses->redirect($this->path($project) . $back);
    }

    public function strength(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        try {
            $this->research->request(WebContext::user($request), $project, 'serp_strength', null, '', null, null, ClientInfo::fromRequest($request), runAgain: \SeoControlCenter\Modules\Shared\Http\RepeatConfirmation::requested($request));
            WebContext::session($request)->flash('status', 'Competitor strength check queued. It runs at the next cron run; then choose Analyze now to use it.');
        } catch (\SeoControlCenter\Modules\Research\RepeatedLookup $e) {
            \SeoControlCenter\Modules\Shared\Http\RepeatConfirmation::flash($request, $e->getMessage());
        } catch (ValidationException $e) {
            WebContext::session($request)->flash('error', implode(' ', $e->errors()));
        }

        return $this->responses->redirect($this->path($project) . '/business#strength');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>|null $old
     */
    private function page(ServerRequestInterface $request, Project $project, array $errors, ?array $old, int $status): ResponseInterface
    {
        $user = WebContext::user($request);

        return $this->responses->html($this->views->render('opportunities/business', [
            'project' => $project,
            ...$this->profiles->page($user, $project),
            'strength' => $this->research->strengthCheck($user, $project),
            'errors' => $errors,
            'old' => $old,
            'models' => BusinessContext::MODELS,
            'goals' => BusinessContext::GOALS,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }

    private function path(Project $project): string
    {
        return '/projects/' . $project->publicId . '/opportunities';
    }
}
