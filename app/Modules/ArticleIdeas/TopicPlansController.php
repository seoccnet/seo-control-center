<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ArticleIdeas;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/** Research → Topic plans (ADR-041, G2). */
final readonly class TopicPlansController
{
    public function __construct(private TopicPlanService $plans, private ProjectService $projects, private ResponseFactory $responses, private ViewRenderer $views) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);

        return $this->html($request, 'topic-plans/index', ['project' => $project, 'plans' => $this->plans->plans($project)]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);

        return $this->html($request, 'topic-plans/show', ['project' => $project, ...$this->plans->view(WebContext::user($request), $project, WebContext::routeParam($request, 'plan'))]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        try {
            $plan = $this->plans->create(WebContext::user($request), $project, WebContext::routeParam($request, 'idea'), ClientInfo::fromRequest($request));
        } catch (HttpException $e) {
            if ($e->statusCode() !== 429) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());

            return $this->responses->redirect('/projects/' . $project->publicId . '/article-ideas');
        }
        WebContext::session($request)->flash('status', 'Topic plan ready. Keep or drop the proposed articles, searches and pages; the link map is built in the background.');

        return $this->responses->redirect($this->url($project, $plan->publicId));
    }

    public function keep(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $kept = WebContext::body($request)['keep'] ?? [];
        $keys = is_array($kept) ? array_values(array_filter($kept, is_string(...))) : [];

        return $this->handle($request, $project, function () use ($request, $project, $keys): string {
            $this->plans->keep(WebContext::user($request), $project, WebContext::routeParam($request, 'plan'), $keys);

            return 'Plan saved. The link map is rebuilt in the background.';
        });
    }

    public function links(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);

        return $this->handle($request, $project, function () use ($request, $project): string {
            $this->plans->requestLinks(WebContext::user($request), $project, WebContext::routeParam($request, 'plan'));

            return 'Rebuilding the link map from your newest site audit, for free.';
        });
    }

    public function backlinks(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);

        return $this->handle($request, $project, function () use ($request, $project, $body): string {
            $this->plans->requestBacklinks(WebContext::user($request), $project, WebContext::routeParam($request, 'plan'), ($body['confirm_paid'] ?? null) === '1', ($body['confirm_repeat'] ?? null) === '1', ClientInfo::fromRequest($request));

            return 'Checking the backlinks of the competitor pages. The result appears here within a few minutes.';
        });
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $this->plans->delete(WebContext::user($request), $project, WebContext::routeParam($request, 'plan'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Topic plan deleted. The article ideas are unchanged.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/topic-plans');
    }

    /** Validation, conflict and limit messages go back to the plan; other errors stay errors. */
    private function handle(ServerRequestInterface $request, Project $project, \Closure $action): ResponseInterface
    {
        try {
            WebContext::session($request)->flash('status', $action());
        } catch (HttpException $e) {
            if (!in_array($e->statusCode(), [409, 422, 429], true)) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());
        }

        return $this->responses->redirect($this->url($project, WebContext::routeParam($request, 'plan')));
    }

    /** @param array<string, mixed> $data */
    private function html(ServerRequestInterface $request, string $template, array $data): ResponseInterface
    {
        return $this->responses->html($this->views->render($template, $data, $request))->withHeader('Cache-Control', 'no-store');
    }

    private function url(Project $project, string $plan): string
    {
        return '/projects/' . $project->publicId . '/topic-plans/' . $plan;
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
