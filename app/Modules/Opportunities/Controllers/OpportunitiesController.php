<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Csv;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Opportunities\OpportunityService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Opportunities list, status changes and on-demand analysis.
 */
final readonly class OpportunitiesController
{
    public function __construct(
        private ProjectService $projects,
        private OpportunityService $opportunities,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private Clock $clock,
        private \SeoControlCenter\Modules\Opportunities\BusinessProfileService $business,
        /** @var (\Closure(\SeoControlCenter\Domain\Projects\Project, int): list<\SeoControlCenter\Domain\ArticleIdeas\ArticleIdea>)|null */
        private ?\Closure $articleIdeas = null,
        /** @var (\Closure(\SeoControlCenter\Domain\Projects\Project, string): ?string)|null this site's measured record for an action plan, as one sentence (0.45.0) */
        private ?\Closure $ownRecord = null,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $query = $request->getQueryParams();
        $status = is_string($query['status'] ?? null) ? $query['status'] : 'open';
        $type = OpportunityType::tryFrom(is_string($query['type'] ?? null) ? $query['type'] : '');
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int) $query['page'] : 1;

        $business = $this->business->page(WebContext::user($request), $project);

        return $this->responses->html($this->views->render('opportunities/index', [
            'project' => $project,
            ...$this->opportunities->overview(WebContext::user($request), $project, $status, $type, $page, $this->filter($query)),
            'business' => $business['profile'],
            'ratings' => $business['ratings'],
            'articleIdeas' => $this->articleIdeas !== null ? ($this->articleIdeas)($project, 3) : null,
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function outcomes(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        return $this->responses->html($this->views->render('opportunities/outcomes', ['project' => $project, 'outcomes' => $this->opportunities->outcomes($project), 'canEdit' => $this->opportunities->canEdit(WebContext::user($request), $project)], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function confirmOutcome(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $implemented = ($body['implemented'] ?? null) === '1';
        $this->opportunities->confirmOutcome(WebContext::user($request), $project, WebContext::routeParam($request, 'outcome'), $implemented, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', $implemented ? 'Confirmed as done.' : 'Marked as not done. The result no longer counts toward the estimate calibration.');

        return $this->responses->redirect($this->path($project) . '/outcomes');
    }

    public function weekly(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        return $this->responses->html($this->views->render('opportunities/weekly', ['project' => $project, ...$this->opportunities->weekly($project)], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function weeklyPlan(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        return $this->responses->json($this->opportunities->weekly($project))->withHeader('Cache-Control', 'no-store');
    }

    public function byPage(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        return $this->responses->html($this->views->render('opportunities/by-page', ['project' => $project, ...$this->opportunities->pagePlans($project)], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function pagePlans(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $plans = $this->opportunities->pagePlans($project);
        // Compact per action; the full plan is on each finding's JSON.
        $plans['action_plans'] = array_map(static fn(\SeoControlCenter\Domain\Opportunities\Playbook $plan): array => ['key' => $plan->key, 'title' => $plan->title, 'first_change' => $plan->firstChange()], $plans['action_plans']);
        return $this->responses->json($plans)->withHeader('Cache-Control', 'no-store');
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $query = $request->getQueryParams();
        $status = is_string($query['status'] ?? null) ? $query['status'] : 'open';
        $type = OpportunityType::tryFrom(is_string($query['type'] ?? null) ? $query['type'] : '');

        return $this->responses->csv($this->opportunities->exportRows($project, $status, $type, $this->filter($query)), Csv::fileName($project->name, 'opportunities', $this->clock->now()->format('Y-m-d')));
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $status = is_string($body['status'] ?? null) ? $body['status'] : '';
        $this->opportunities->setStatus(WebContext::user($request), $project, WebContext::routeParam($request, 'opportunity'), $status, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', match ($status) {
            'done' => 'Marked as done.',
            'dismissed' => 'Dismissed. It will not be shown as open again.',
            default => 'Opened again.',
        });
        $back = is_string($body['back'] ?? null) && str_starts_with($body['back'], '?') ? $body['back'] : '';

        return $this->responses->redirect($this->path($project) . $back);
    }

    public function analyze(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $result = $this->opportunities->analyzeNow(WebContext::user($request), $project);
        WebContext::session($request)->flash('status', sprintf(
            'Analysis finished: %d new, %d updated, %d resolved.',
            $result['opened'],
            $result['updated'],
            $result['resolved'],
        ));

        return $this->responses->redirect($this->path($project));
    }

    /** @param array<string, mixed> $query */
    private function filter(array $query): \SeoControlCenter\Domain\Opportunities\OpportunityFilter
    {
        try {
            return \SeoControlCenter\Domain\Opportunities\OpportunityFilter::fromArray($query);
        } catch (\InvalidArgumentException $error) {
            throw new \SeoControlCenter\Core\Http\Exception\HttpException(400, $error->getMessage());
        }
    }

    public function detail(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $detail = $this->opportunities->detail($project, WebContext::routeParam($request, 'opportunity'));
        $own = $this->ownRecord !== null ? ($this->ownRecord)($project, $detail['action_plan']->key) : null;
        return $this->responses->html($this->views->render('opportunities/detail', ['project' => $project, ...$detail, 'own_record' => $own, 'canEdit' => $this->projects->can(WebContext::user($request), \SeoControlCenter\Core\Authorization\Permission::ProjectsEdit, $project)], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function data(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $detail = $this->opportunities->detail($project, WebContext::routeParam($request, 'opportunity'));
        $detail['opportunity'] = $detail['opportunity']->present();
        $detail['supporting'] = array_map(static fn(\SeoControlCenter\Domain\Opportunities\StoredOpportunity $row): array => $row->present(), $detail['supporting']);
        $detail['action_plan'] = $detail['action_plan']->toArray();
        return $this->responses->json($detail)->withHeader('Cache-Control', 'no-store');
    }

    public function snooze(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $until = is_string($body['until'] ?? null) ? $body['until'] : '';
        $this->opportunities->snooze(WebContext::user($request), $project, WebContext::routeParam($request, 'opportunity'), $until, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Snoozed until ' . $until . '. It resumes at the next analysis on or after that date.');
        return $this->responses->redirect($this->path($project));
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
        return '/projects/' . $project->publicId . '/opportunities';
    }
}
