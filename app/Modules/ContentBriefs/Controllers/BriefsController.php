<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentBriefs\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\ContentBriefs\BriefBuilder;
use SeoControlCenter\Modules\ContentBriefs\BriefService;
use SeoControlCenter\Modules\ContentBriefs\ContentBrief;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class BriefsController
{
    /**
     * @param (\Closure(Project, string): ?array{keyword: string, url: string, sections: array<string, string>, evidence: list<string>})|null $ideaSeed
     *        prefill from an article idea (ADR-041), when that module is enabled
     * @param (\Closure(Project, list<string>): array<string, list<array{url: ?string, title: ?string, state: string, state_label: string, sources: list<string>, article: bool}>>)|null $inventory
     *        content-inventory rows already covering the keyword (ADR-042), shown before a new brief is written
     */
    public function __construct(private ProjectService $projects, private BriefService $briefs, private BriefBuilder $builder, private ResponseFactory $responses, private ViewRenderer $views, private ?\Closure $ideaSeed = null, private ?\Closure $inventory = null, private ?\SeoControlCenter\Modules\ContentBriefs\ClaimService $claims = null) {}
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $query = $request->getQueryParams();
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $listing = $this->briefs->listing($project, BriefService::text($query, 'status'), BriefService::text($query, 'q'), $page === false ? 1 : $page);
        $progress = [];
        foreach ($listing['items'] as $item) {
            $progress[$item->publicId] = $this->briefs->progress($item);
        }
        return $this->html($request, 'content-briefs/index', ['project' => $project, 'canEdit' => $this->projects->can(WebContext::user($request), Permission::ProjectsEdit, $project), 'listing' => $listing, 'progress' => $progress, 'counts' => $this->briefs->statusCounts($project)]);
    }
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $this->briefs->authorizeEdit(WebContext::user($request), $project);
        $query = $request->getQueryParams();
        $ideaKey = BriefService::text($query, 'article_idea');
        $idea = $this->ideaSeed !== null && preg_match('/^[a-f0-9]{64}$/', $ideaKey) === 1 ? ($this->ideaSeed)($project, $ideaKey) : null;
        try {
            $seed = $this->builder->build($project, mb_substr($idea['keyword'] ?? BriefService::text($query, 'keyword'), 0, 200), $idea['url'] ?? BriefService::text($query, 'url'), BriefService::text($query, 'keyword_id'), BriefService::text($query, 'opportunity_id'));
            if ($idea !== null) {
                $seed['input'] = array_replace($seed['input'], $idea['sections']);
                $seed['evidence'] = [...$seed['evidence'], ...$idea['evidence']];
            }
            return $this->form($request, $project, $seed['input'] + ['keyword_id' => BriefService::text($query, 'keyword_id'), 'opportunity_id' => BriefService::text($query, 'opportunity_id')], $seed['evidence']);
        } catch (ValidationException $e) {
            return $this->form($request, $project, $query, [], $e->errors(), 422);
        }
    }
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $brief = $this->briefs->get($project, WebContext::routeParam($request, 'brief'));
        return $this->html($request, 'content-briefs/show', ['project' => $project, 'brief' => $brief, 'progress' => $this->briefs->progress($brief), 'claims' => $this->claims?->forBrief($project, $brief->publicId), 'canEdit' => $this->projects->can(WebContext::user($request), Permission::ProjectsEdit, $project), 'print' => str_ends_with($request->getUri()->getPath(), '/print')]);
    }
    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $brief = $this->briefs->get($project, WebContext::routeParam($request, 'brief'));
        $this->briefs->authorizeEdit(WebContext::user($request), $project);
        return $this->form($request, $project, $brief->input->form() + ['revision' => (string) $brief->revision], $brief->evidence, brief: $brief);
    }
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        return $this->save($request);
    }
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        return $this->save($request, WebContext::routeParam($request, 'brief'));
    }
    private function save(ServerRequestInterface $request, ?string $id = null): ResponseInterface
    {
        $project = $this->project($request);
        $old = $id === null ? null : $this->briefs->get($project, $id);
        try {
            $brief = $this->briefs->save(WebContext::user($request), $project, WebContext::body($request), ClientInfo::fromRequest($request), $id);
        } catch (ValidationException $e) {
            return $this->form($request, $project, WebContext::body($request), $old === null ? [] : $old->evidence, $e->errors(), 422, $old);
        } catch (HttpException $e) {
            if ($e->statusCode() !== 409) {
                throw $e;
            }
            return $this->form($request, $project, WebContext::body($request), $old === null ? [] : $old->evidence, ['conflict' => $e->getMessage()], 409, $old);
        }
        WebContext::session($request)->flash('status', 'Content brief saved. Workflow status does not publish or edit your website.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/content-briefs/' . $brief->publicId);
    }
    public function remove(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $this->briefs->remove(WebContext::user($request), $project, WebContext::routeParam($request, 'brief'), WebContext::body($request), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Content brief removed. Website and change timeline are unchanged.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/content-briefs');
    }
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $brief = $this->briefs->get($project, WebContext::routeParam($request, 'brief'));
        return $this->responses->text($this->briefs->export($brief))->withHeader('Content-Disposition', 'attachment; filename="content-brief-' . $brief->publicId . '.txt"')->withHeader('Cache-Control', 'no-store')->withHeader('X-Content-Type-Options', 'nosniff');
    }
    /**
     * @param array<string, mixed> $input
     * @param list<string> $evidence
     * @param array<string, string> $errors
     */
    private function form(ServerRequestInterface $request, Project $project, array $input, array $evidence, array $errors = [], int $status = 200, ?ContentBrief $brief = null): ResponseInterface
    {
        $keyword = is_string($input['keyword'] ?? null) ? trim($input['keyword']) : '';
        $existing = $brief === null && $keyword !== '' && $this->inventory !== null ? (($this->inventory)($project, [$keyword])[$keyword] ?? []) : [];

        return $this->html($request, 'content-briefs/form', ['project' => $project, 'input' => $input, 'evidence' => $evidence, 'errors' => $errors, 'brief' => $brief, 'existing' => $existing], $status);
    }
    /** @param array<string, mixed> $data */
    private function html(ServerRequestInterface $request, string $template, array $data, int $status = 200): ResponseInterface
    {
        return $this->responses->html($this->views->render($template, $data, $request), $status)->withHeader('Cache-Control', 'no-store');
    }
    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
