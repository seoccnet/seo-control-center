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
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Research\ResearchService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/** Research → Article ideas (ADR-041, G1). */
final readonly class ArticleIdeasController
{
    public function __construct(private ArticleIdeaService $ideas, private ProjectService $projects, private ResponseFactory $responses, private ViewRenderer $views, private ?ResearchService $research = null, private ?ArticleTrackingService $tracking = null) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);

        $market = $request->getQueryParams()['market'] ?? null;

        return $this->responses->html($this->views->render('article-ideas/index', ['project' => $project, ...$this->ideas->overview(WebContext::user($request), $project, is_string($market) && $market !== '' ? $market : null)], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function start(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);

        return $this->handle($request, $project, function () use ($request, $project, $body): string {
            $confirmed = $body['confirmed_cost'] ?? null;
            $this->ideas->start(WebContext::user($request), $project, ($body['confirm_paid'] ?? null) === '1', ($body['confirm_repeat'] ?? null) === '1', ClientInfo::fromRequest($request), is_string($confirmed) && is_numeric($confirmed) ? (float) $confirmed : null);
            $skipped = $this->ideas->marketPlan($project)['skipped'];

            return 'Looking up competitor articles. Ideas appear here within a few minutes.'
                . ($skipped !== [] ? ' Skipped, because DataForSEO Labs has no data for them: ' . implode(', ', array_column($skipped, 'label')) . '.' : '');
        });
    }

    public function rebuild(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);

        return $this->handle($request, $project, function () use ($request, $project): string {
            $this->ideas->requestRebuild(WebContext::user($request), $project, ClientInfo::fromRequest($request));

            return 'Rebuilding the ideas from the stored lookup, for free. They update here within a few minutes.';
        });
    }

    public function outline(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);

        return $this->handle($request, $project, function () use ($request, $project): string {
            $this->ideas->requestOutline(WebContext::user($request), $project, WebContext::routeParam($request, 'idea'), ClientInfo::fromRequest($request));

            return 'Reading the competitor outlines, for free. They appear on the idea within a few minutes.';
        });
    }

    public function published(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);

        return $this->handle($request, $project, function () use ($request, $project, $body): string {
            if ($this->tracking === null) {
                throw new HttpException(409, 'Tracking published articles is not available.');
            }
            try {
                $tracking = $this->tracking->markPublished(WebContext::user($request), $project, WebContext::routeParam($request, 'idea'), is_string($body['url'] ?? null) ? $body['url'] : '', is_string($body['published_on'] ?? null) ? $body['published_on'] : '', ClientInfo::fromRequest($request));
            } catch (ValidationException $e) {
                throw new HttpException(422, implode(' ', $e->errors()));
            }

            return sprintf('Tracking “%s”. Its clicks and position are compared with the prediction every day from your Search Console data.', $tracking->mainKeyword);
        });
    }

    public function stopTracking(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        if ($this->tracking === null) {
            throw new \SeoControlCenter\Core\Http\Exception\NotFoundHttpException();
        }
        $this->tracking->stop(WebContext::user($request), $project, WebContext::routeParam($request, 'tracking'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Tracking stopped. The article idea is unchanged.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/article-ideas#published');
    }

    public function dismiss(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $notRelevant = (WebContext::body($request)['reason'] ?? null) === 'not_relevant';

        return $this->handle($request, $project, function () use ($request, $project, $notRelevant): string {
            $idea = $this->ideas->dismiss(WebContext::user($request), $project, WebContext::routeParam($request, 'idea'), ClientInfo::fromRequest($request));
            if ($notRelevant && $this->research !== null) {
                try {
                    $this->research->setRelevance(WebContext::user($request), $project, $idea->mainKeyword, 'not_relevant', ClientInfo::fromRequest($request));
                } catch (ValidationException) {
                    return 'Idea hidden. (Relevance could not be saved: business profiles are not available.)';
                }

                return sprintf('Idea hidden, and “%s” marked as not relevant for future research.', $idea->mainKeyword);
            }

            return 'Idea hidden.';
        });
    }

    /** Validation, conflict and limit messages go back to the page; other errors stay errors. */
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

        return $this->responses->redirect('/projects/' . $project->publicId . '/article-ideas');
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
