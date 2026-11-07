<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Csv;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Modules\SiteAudit\SiteAudit;
use SeoControlCenter\Modules\SiteAudit\SiteAuditService;

/**
 * Project → Site audit: start and stop audits, settings, the issues of an
 * audit, its crawled pages and the CSV export.
 */
final readonly class SiteAuditController
{
    private const int ISSUES_PER_PAGE = 50;
    private const int PAGES_PER_PAGE = 100;
    /** Issue types whose rows list the pages linking to them. */
    private const array WITH_SOURCES = [IssueType::BrokenPage, IssueType::FetchRefused, IssueType::ServerError, IssueType::Unreachable, IssueType::HttpUrl, IssueType::Redirect, IssueType::RedirectChain, IssueType::BlockedByRobots];

    public function __construct(
        private SiteAuditService $service,
        private ProjectService $projects,
        private FeatureManager $features,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private \SeoControlCenter\Modules\WebVitals\WebVitalsRepository $vitals,
        private \SeoControlCenter\Modules\SiteAudit\RenderedAuditService $rendered,
        private ?\SeoControlCenter\Modules\SiteAudit\TitleTriage $titleTriage = null,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, [], 200);
    }

    public function start(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $audit = $this->service->start(WebContext::user($request), $project, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', sprintf('Audit started. Up to %d pages are crawled in the background; this page updates itself.', $audit->maxPages));

        return $this->responses->redirect($this->path($project));
    }

    public function render(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $limit = is_string($body['max_pages'] ?? null) && ctype_digit($body['max_pages']) ? (int) $body['max_pages'] : 1;
        $this->rendered->request(WebContext::user($request), $project, is_string($body['url'] ?? null) ? $body['url'] : '', ClientInfo::fromRequest($request), ($body['fresh'] ?? null) === '1', $limit, ($body['dismiss_cookie_popup'] ?? null) === '1', is_string($body['mode'] ?? null) ? $body['mode'] : 'browser');
        WebContext::session($request)->flash('status', 'Rendered crawl queued. Cron submits one paid task and retrieves saved pages and resource evidence later.');
        return $this->responses->redirect($this->path($project));
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $this->service->cancel(WebContext::user($request), $project, WebContext::routeParam($request, 'audit'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Audit stopped.');

        return $this->responses->redirect($this->path($project));
    }

    public function settings(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $maxPages = is_string($body['max_pages'] ?? null) && ctype_digit($body['max_pages']) ? (int) $body['max_pages'] : 0;
        try {
            $this->service->saveSettings(WebContext::user($request), $project, $maxPages, ($body['weekly'] ?? null) === '1', ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), 422);
        }
        WebContext::session($request)->flash('status', 'Audit settings saved.');

        return $this->responses->redirect($this->path($project));
    }

    public function pages(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $audit = $this->service->current($project, WebContext::routeParam($request, 'audit')) ?? throw new NotFoundHttpException();
        $page = self::pageNumber($request);
        $total = $audit->pagesCrawled;

        return $this->responses->html($this->views->render('site-audit/pages', [
            'project' => $project,
            'audit' => $audit,
            'pages' => $this->service->pages($audit, ($page - 1) * self::PAGES_PER_PAGE, self::PAGES_PER_PAGE),
            'page' => $page,
            'pageCount' => max(1, (int) ceil($total / self::PAGES_PER_PAGE)),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $audit = $this->service->current($project, WebContext::routeParam($request, 'audit')) ?? throw new NotFoundHttpException();
        $rows = [['URL', 'Issue', 'Severity', 'Detail']];
        foreach ($this->service->issues($audit, null, 0, 100000) as $issue) {
            $rows[] = [$issue['url'], $issue['type']->label(), $issue['type']->severity(), $issue['detail']];
        }

        return $this->responses->csv($rows, Csv::fileName($project->name, 'site-audit', substr($audit->createdAt, 0, 10)));
    }

    /**
     * @param array<string, string> $errors
     */
    private function page(ServerRequestInterface $request, array $errors, int $status): ResponseInterface
    {
        $project = $this->load($request);
        $user = WebContext::user($request);
        $params = $request->getAttribute(RequestAttribute::ROUTE_PARAMS);
        $auditId = is_array($params) && is_string($params['audit'] ?? null) ? $params['audit'] : null;
        $audit = $this->service->current($project, $auditId);
        $query = $request->getQueryParams();
        $renderedHistory = $this->rendered->history($project);
        $renderedEvidence = $this->rendered->current($project);
        if (isset($query['rendered_snapshot'])) {
            $renderedEvidence = null;
            foreach ($renderedHistory as $snapshot) {
                if (is_string($query['rendered_snapshot']) && ($snapshot->params['_request_id'] ?? null) === $query['rendered_snapshot']) {
                    $renderedEvidence = $snapshot;
                    break;
                }
            }
            if ($renderedEvidence === null) {
                throw new NotFoundHttpException();
            }
        }
        $type = IssueType::tryFrom(is_string($query['type'] ?? null) ? $query['type'] : '');
        $page = self::pageNumber($request);

        $counts = [];
        $previousCounts = null;
        $previous = null;
        $issues = [];
        if ($audit !== null && $audit->isCompleted()) {
            $counts = $this->service->issueCounts($audit);
            $previous = $this->service->previous($project, $audit);
            $previousCounts = $previous !== null ? $this->service->issueCounts($previous) : null;
            foreach ($this->service->issues($audit, $type, ($page - 1) * self::ISSUES_PER_PAGE, self::ISSUES_PER_PAGE) as $issue) {
                $issue['sources'] = in_array($issue['type'], self::WITH_SOURCES, true) ? $this->service->linkSources($audit, $issue['pageId'], 3) : [];
                $issues[] = $issue;
            }
        }
        $issueTotal = $type !== null ? ($counts[$type->value] ?? 0) : array_sum($counts);

        return $this->responses->html($this->views->render('site-audit/index', [
            'project' => $project,
            // Which long titles cost clicks (0.48.0), read only on the "Title too long" filter.
            'titleTriage' => $type === IssueType::TitleTooLong && $audit !== null && $audit->isCompleted() && $page === 1 ? $this->titleTriage?->triage($project, $audit) : null,
            'webVitals' => $this->vitals->list($project->id, 100),
            'renderedResult' => $this->rendered->current($project),
            'renderedAvailable' => $this->rendered->available($project),
            'renderedHistory' => $renderedHistory,
            'renderedEvidence' => $renderedEvidence,
            'audit' => $audit,
            'previous' => $previous,
            'history' => $this->service->history($project),
            'settings' => $this->service->settings($project),
            'counts' => $counts,
            'previousCounts' => $previousCounts,
            'issues' => $issues,
            'type' => $type,
            'page' => $page,
            'pageCount' => max(1, (int) ceil($issueTotal / self::ISSUES_PER_PAGE)),
            'canRun' => $this->projects->can($user, Permission::AnalysisExecute, $project),
            'canEdit' => $this->projects->can($user, Permission::ProjectsEdit, $project),
            'featureOn' => $this->features->enabled(Feature::SiteAudit),
            'errors' => $errors,
            'isLatest' => $auditId === null || ($audit instanceof SiteAudit && ($this->service->history($project)[0] ?? null)?->id === $audit->id),
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }

    private static function pageNumber(ServerRequestInterface $request): int
    {
        $value = $request->getQueryParams()['page'] ?? null;

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? min((int) $value, 10000) : 1;
    }

    private function path(Project $project): string
    {
        return '/projects/' . $project->publicId . '/site-audit';
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
