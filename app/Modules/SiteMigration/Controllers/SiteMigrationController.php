<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteMigration\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\SiteMigration\SiteMigration;
use SeoControlCenter\Modules\SiteMigration\SiteMigrationService;

/** Site migration plans (1.2.0): thin HTTP layer over SiteMigrationService. */
final readonly class SiteMigrationController
{
    public const int PER_PAGE = 100;

    public function __construct(
        private ProjectService $projects,
        private SiteMigrationService $migrations,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $user = WebContext::user($request);

        return $this->responses->html($this->views->render('site-migration/index', [
            'project' => $project,
            'plans' => $this->migrations->list($project),
            'canEdit' => $this->migrations->canEdit($user, $project),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $base = $this->base($project);
        try {
            $plan = $this->migrations->create(WebContext::user($request), $project, (array) $request->getParsedBody(), ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->fail($request, $base, $e);
        }
        WebContext::session($request)->flash('status', 'Migration plan created. Add the old addresses and the new site’s addresses next.');

        return $this->responses->redirect($base . '/' . $plan->publicId);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $plan = $this->plan($request, $project);
        $user = WebContext::user($request);
        $query = $request->getQueryParams();
        $filter = is_string($query['filter'] ?? null) && $query['filter'] !== '' ? $query['filter'] : null;
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $page = $page !== false ? max(1, min(1000, $page)) : 1;
        $rows = $this->migrations->rows($plan, $filter, $page, self::PER_PAGE);

        return $this->responses->html($this->views->render('site-migration/show', [
            'project' => $project,
            'plan' => $plan,
            ...$rows,
            'filter' => $filter,
            'page' => $page,
            'pageCount' => max(1, (int) ceil($rows['total'] / self::PER_PAGE)),
            'results' => $this->migrations->results($project, $plan),
            'canEdit' => $this->migrations->canEdit($user, $project),
            'canTest' => $this->migrations->canTest($user, $project),
            'maxUrls' => SiteMigrationService::MAX_URLS,
            'maxTargets' => SiteMigrationService::MAX_TARGETS,
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, '#plan-settings', 'Plan saved.', function (Project $project, SiteMigration $plan, array $body) use ($request): void {
            $this->migrations->update(WebContext::user($request), $project, $plan, $body, ClientInfo::fromRequest($request));
        });
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $plan = $this->plan($request, $project);
        $this->migrations->delete(WebContext::user($request), $project, $plan, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Migration plan deleted.');

        return $this->responses->redirect($this->base($project));
    }

    public function importOld(ServerRequestInterface $request): ResponseInterface
    {
        $result = null;
        $response = $this->act($request, '#plan-addresses', null, function (Project $project, SiteMigration $plan, array $body) use ($request, &$result): mixed {
            $source = in_array($body['source'] ?? null, ['paste', 'search_console', 'site_audit'], true) ? $body['source'] : 'paste';

            return $result = $this->migrations->importOld(WebContext::user($request), $project, $plan, $source, is_string($body['urls'] ?? null) ? $body['urls'] : '', ClientInfo::fromRequest($request));
        });
        if (is_array($result)) {
            WebContext::session($request)->flash('status', sprintf('%s old address%s added%s.', number_format($result['added']), $result['added'] === 1 ? '' : 'es', $result['ignored'] > 0 ? sprintf('; %s skipped (already in the plan, on another host or over the limit)', number_format($result['ignored'])) : ''));
        }

        return $response;
    }

    public function importNew(ServerRequestInterface $request): ResponseInterface
    {
        $count = null;
        $response = $this->act($request, '#plan-addresses', null, function (Project $project, SiteMigration $plan, array $body) use ($request, &$count): mixed {
            $source = ($body['source'] ?? null) === 'site_audit' ? 'site_audit' : 'paste';

            return $count = $this->migrations->importNew(WebContext::user($request), $project, $plan, $source, is_string($body['urls'] ?? null) ? $body['urls'] : '', ClientInfo::fromRequest($request));
        });
        if (is_int($count)) {
            WebContext::session($request)->flash('status', sprintf('%s new address%s saved. Suggest redirects to match them.', number_format($count), $count === 1 ? '' : 'es'));
        }

        return $response;
    }

    public function suggest(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, '#plan-redirects', 'Suggesting redirects in the background. Reload in a minute.', function (Project $project, SiteMigration $plan) use ($request): void {
            $this->migrations->suggest(WebContext::user($request), $project, $plan, ClientInfo::fromRequest($request));
        });
    }

    public function test(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, '#plan-redirects', 'Redirect test queued. Background tasks check each old address; reload to follow the progress.', function (Project $project, SiteMigration $plan) use ($request): void {
            $this->migrations->test(WebContext::user($request), $project, $plan, ClientInfo::fromRequest($request));
        });
    }

    public function target(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, '#plan-redirects', 'Target saved.', function (Project $project, SiteMigration $plan, array $body) use ($request): void {
            $this->migrations->setTarget(WebContext::user($request), $project, $plan, is_string($body['key'] ?? null) ? $body['key'] : '', is_string($body['target'] ?? null) ? $body['target'] : '');
        });
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $plan = $this->plan($request, $project);
        $format = $request->getQueryParams()['format'] ?? 'csv';
        $file = $this->migrations->export($project, $plan, in_array($format, ['csv', 'apache', 'nginx'], true) ? $format : 'csv');

        return $this->responses->text($file['body'])
            ->withHeader('Content-Type', $file['type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['fileName'] . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Runs a change on the plan and returns to it, showing validation and
     * expected refusals (409, 422, 429) as a message.
     *
     * @param \Closure(Project, SiteMigration, array<string, mixed>): mixed $action
     */
    private function act(ServerRequestInterface $request, string $anchor, ?string $status, \Closure $action): ResponseInterface
    {
        $project = $this->project($request);
        $plan = $this->plan($request, $project);
        $back = $this->base($project) . '/' . $plan->publicId;
        try {
            /** @var array<string, mixed> $body */
            $body = (array) $request->getParsedBody();
            $action($project, $plan, $body);
        } catch (ValidationException $e) {
            return $this->fail($request, $back . $anchor, $e);
        } catch (HttpException $e) {
            if (!in_array($e->statusCode(), [409, 422, 429], true)) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());

            return $this->responses->redirect($back . $anchor);
        }
        if ($status !== null) {
            WebContext::session($request)->flash('status', $status);
        }

        return $this->responses->redirect($back . $anchor);
    }

    private function fail(ServerRequestInterface $request, string $back, ValidationException $e): ResponseInterface
    {
        WebContext::session($request)->flash('error', implode(' ', $e->errors()));

        return $this->responses->redirect($back);
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }

    private function plan(ServerRequestInterface $request, Project $project): SiteMigration
    {
        return $this->migrations->get($project, WebContext::routeParam($request, 'plan'));
    }

    private function base(Project $project): string
    {
        return '/projects/' . $project->publicId . '/site-migration';
    }
}
