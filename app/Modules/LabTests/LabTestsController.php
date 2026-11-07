<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\LabTests;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/** Improve → Speed tests (ADR-040, F2). */
final readonly class LabTestsController
{
    public function __construct(private LabTestService $tests, private ProjectService $projects, private ResponseFactory $responses, private ViewRenderer $views) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);

        return $this->responses->html($this->views->render('lab-tests/index', ['project' => $project, ...$this->tests->overview(WebContext::user($request), $project)], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function add(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);
        $text = static fn(string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';

        return $this->handle($request, $project, function () use ($request, $project, $text, $body): string {
            $profile = $this->tests->addProfile(WebContext::user($request), $project, $text('url'), $text('device'), $text('source'), ($body['weekly'] ?? null) === '1', ClientInfo::fromRequest($request));
            if (!$profile->paid()) {
                $this->tests->runNow(WebContext::user($request), $project, $profile->publicId, false, ClientInfo::fromRequest($request));

                return 'Profile saved. The first PageSpeed run is queued and usually finishes within a few minutes.';
            }

            return 'Profile saved. Start a paid run when you need one.';
        });
    }

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $confirm = (WebContext::body($request)['confirm_paid'] ?? null) === '1';

        return $this->handle($request, $project, function () use ($request, $project, $confirm): string {
            $this->tests->runNow(WebContext::user($request), $project, WebContext::routeParam($request, 'profile'), $confirm, ClientInfo::fromRequest($request));

            return 'Run queued. Results appear here within a few minutes.';
        });
    }

    public function weekly(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $weekly = (WebContext::body($request)['weekly'] ?? null) === '1';

        return $this->handle($request, $project, function () use ($request, $project, $weekly): string {
            $this->tests->setWeekly(WebContext::user($request), $project, WebContext::routeParam($request, 'profile'), $weekly, ClientInfo::fromRequest($request));

            return $weekly ? 'Weekly runs on.' : 'Weekly runs off.';
        });
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);

        return $this->handle($request, $project, function () use ($request, $project): string {
            $this->tests->deleteProfile(WebContext::user($request), $project, WebContext::routeParam($request, 'profile'), ClientInfo::fromRequest($request));

            return 'Profile and its runs deleted.';
        });
    }

    /** Validation and conflict messages go back to the page; other errors (403, 404) stay errors. */
    private function handle(ServerRequestInterface $request, Project $project, \Closure $action): ResponseInterface
    {
        try {
            $message = $action();
            WebContext::session($request)->flash('status', $message);
        } catch (HttpException $e) {
            if (!in_array($e->statusCode(), [409, 422, 429], true)) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());
        }

        return $this->responses->redirect('/projects/' . $project->publicId . '/lab-tests');
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
