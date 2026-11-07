<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Annotations\AnnotationService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class AnnotationsController
{
    public function __construct(private ProjectService $projects, private AnnotationService $annotations, private ResponseFactory $responses, private ViewRenderer $views, private Clock $clock, private \SeoControlCenter\Modules\Annotations\ChangeImpactService $impact, private \SeoControlCenter\Modules\Annotations\MeasurementPlanService $measurement, private ?\SeoControlCenter\Modules\Annotations\ResultsLedgerService $ledger = null) {}

    /** What worked on your site (0.45.0): measured results by kind of change and by action plan. */
    public function results(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        if ($this->ledger === null) {
            throw new \SeoControlCenter\Core\Http\Exception\NotFoundHttpException('Not found.');
        }

        return $this->responses->html($this->views->render('annotations/results', ['project' => $project, 'ledger' => $this->ledger->ledger(WebContext::user($request), $project), 'canEdit' => $this->projects->can(WebContext::user($request), \SeoControlCenter\Core\Authorization\Permission::ProjectsEdit, $project)], $request))->withHeader('Cache-Control', 'no-store');
    }

    /** Gives past page changes the default plan, a batch per click (0.51.0). */
    public function measurePast(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        if ($this->ledger === null) {
            throw new \SeoControlCenter\Core\Http\Exception\NotFoundHttpException('Not found.');
        }
        $done = $this->ledger->measurePast(WebContext::user($request), $project, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', sprintf('%d past %s measured.', $done['measured'], $done['measured'] === 1 ? 'change' : 'changes')
            . ($done['failed'] > 0 ? sprintf(' %d could not get a plan; open them to see why.', $done['failed']) : '')
            . ($done['left'] > 0 ? sprintf(' %d left: run it again.', $done['left']) : ''));

        return $this->responses->redirect('/projects/' . $project->publicId . '/annotations/results');
    }

    public function impact(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $days = filter_var($request->getQueryParams()['days'] ?? 28, FILTER_VALIDATE_INT);
        $report = $this->impact->compare($project, WebContext::routeParam($request, 'annotation'), $days === false ? 0 : $days);
        $plan = $this->measurement->plan($project, WebContext::routeParam($request, 'annotation'));
        return $this->responses->html($this->views->render('annotations/impact', ['project' => $project, 'report' => $report, 'measurement' => $plan, 'canEdit' => $this->projects->can(WebContext::user($request), \SeoControlCenter\Core\Authorization\Permission::ProjectsEdit, $project)], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function savePlan(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $id = WebContext::routeParam($request, 'annotation');
        $this->measurement->save(WebContext::user($request), $project, $id, WebContext::body($request), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Measurement plan saved.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/annotations/' . $id . '/impact#measurement-plan');
    }

    public function freezePlan(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $id = WebContext::routeParam($request, 'annotation');
        $version = filter_var(WebContext::body($request)['version'] ?? null, FILTER_VALIDATE_INT);
        $this->measurement->freeze(WebContext::user($request), $project, $id, $version === false ? -1 : $version, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Baseline frozen.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/annotations/' . $id . '/impact#measurement-plan');
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, $this->project($request));
    }
    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $id = WebContext::routeParam($request, 'annotation');
        $annotation = $this->annotations->get($project, $id);
        return $this->page($request, $project, ['date' => $annotation->date, 'kind' => $annotation->kind, 'title' => $annotation->title, 'note' => $annotation->note, 'url' => $annotation->url ?? ''], [], 200, $id);
    }
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        return $this->save($request);
    }
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        return $this->save($request, WebContext::routeParam($request, 'annotation'));
    }
    public function remove(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $this->annotations->remove(WebContext::user($request), $project, WebContext::routeParam($request, 'annotation'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Annotation removed from the timeline.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/annotations');
    }
    private function save(ServerRequestInterface $request, ?string $id = null): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);
        try {
            $saved = $this->annotations->save(WebContext::user($request), $project, $body, ClientInfo::fromRequest($request), $id);
        } catch (ValidationException $e) {
            return $this->page($request, $project, $body, $e->errors(), 422, $id);
        }
        // A new page change is measured unless the box was unticked (0.49.0).
        $drafted = $id === null && ($body['measure'] ?? null) === '1' ? $this->measurement->draft(WebContext::user($request), $project, $saved, ClientInfo::fromRequest($request)) : null;
        WebContext::session($request)->flash('status', 'SEO change annotation saved. It records context; it does not change the website.' . ($drafted !== null ? ' ' . $drafted : ''));
        return $this->responses->redirect('/projects/' . $project->publicId . '/annotations');
    }
    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function page(ServerRequestInterface $request, Project $project, array $input = [], array $errors = [], int $status = 200, ?string $editingId = null): ResponseInterface
    {
        $query = $request->getQueryParams();
        $from = is_string($query['from'] ?? null) ? $query['from'] : '';
        $to = is_string($query['to'] ?? null) ? $query['to'] : '';
        $url = is_string($query['url'] ?? null) ? $query['url'] : '';
        $range = null;
        if ($from !== '' || $to !== '') {
            try {
                $range = new DateRange($from, $to);
            } catch (\InvalidArgumentException) {
                $errors['filter'] = 'Choose a valid start and end date, in that order.';
                $status = 422;
            }
        }
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $listing = $this->annotations->listing($project, $range, $url === '' ? null : $url, $page === false ? 1 : $page);
        return $this->responses->html($this->views->render('annotations/index', [
            'project' => $project, 'canEdit' => $this->projects->can(WebContext::user($request), Permission::ProjectsEdit, $project),
            'listing' => $listing, 'insights' => $this->impact->timeline($project, $listing['items']), 'overview' => $this->annotations->overview($project), 'today' => $this->clock->now()->format('Y-m-d'),
            'input' => $input + ['date' => $this->clock->now()->format('Y-m-d'), 'kind' => is_string($query['kind'] ?? null) && isset(\SeoControlCenter\Modules\Annotations\AnnotationInput::KINDS[$query['kind']]) ? $query['kind'] : 'content', 'title' => is_string($query['title'] ?? null) ? mb_substr($query['title'], 0, 120) : '', 'note' => '', 'url' => $url],
            'errors' => $errors, 'editingId' => $editingId, 'from' => $from, 'to' => $to, 'urlFilter' => $url,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }
    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
