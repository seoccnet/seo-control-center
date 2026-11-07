<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Keywords\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Csv;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Keywords\KeywordService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project keywords with their metrics. Reads local data only; metrics are
 * fetched by a background job.
 */
final readonly class KeywordsController
{
    public function __construct(
        private ProjectService $projects,
        private KeywordService $keywords,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private Clock $clock,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $page = $request->getQueryParams()['page'] ?? '1';

        return $this->page($request, $this->load($request), is_string($page) && ctype_digit($page) ? (int) $page : 1, [], [], 200);
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);

        return $this->responses->csv($this->keywords->exportRows($project), Csv::fileName($project->name, 'keywords', $this->clock->now()->format('Y-m-d')));
    }

    public function add(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $text = is_string($body['keywords'] ?? null) ? $body['keywords'] : '';
        // Suggestions from Search Console arrive as checkboxes.
        if (is_array($body['suggested'] ?? null)) {
            $text = implode("\n", array_filter($body['suggested'], 'is_string'));
        }
        try {
            $result = $this->keywords->add(
                WebContext::user($request),
                $project,
                $text,
                $body['location'] ?? null,
                $body['language'] ?? null,
                ClientInfo::fromRequest($request),
            );
        } catch (ValidationException $e) {
            return $this->page($request, $project, 1, $e->errors(), [
                'keywords' => $text,
                'location' => is_string($body['location'] ?? null) ? $body['location'] : '',
                'language' => is_string($body['language'] ?? null) ? $body['language'] : '',
            ], 422);
        }

        $message = sprintf('%d keyword%s added.', $result['added'], $result['added'] === 1 ? '' : 's');
        if ($result['added'] === 0) {
            $message = 'These keywords are already tracked.';
        } elseif ($result['queued']) {
            $message .= ' Metrics are being fetched in the background.';
        }
        WebContext::session($request)->flash('status', $message);

        return $this->responses->redirect($this->path($project));
    }

    public function remove(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $this->keywords->remove(WebContext::user($request), $project, WebContext::routeParam($request, 'keyword'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Keyword removed.');

        return $this->responses->redirect($this->path($project));
    }

    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        try {
            $this->keywords->refresh(WebContext::user($request), $project);
            WebContext::session($request)->flash('status', 'Metrics update queued. Keywords with metrics from the last 30 days are not fetched again.');
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
     * @param array<string, string> $old
     */
    private function page(ServerRequestInterface $request, Project $project, int $page, array $errors, array $old, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('keywords/index', [
            'project' => $project,
            ...$this->keywords->overview(WebContext::user($request), $project, $page),
            'locations' => KeywordLocale::LOCATIONS,
            'languages' => KeywordLocale::LANGUAGES,
            'errors' => $errors,
            'old' => $old,
            'maxKeywords' => KeywordService::MAX_PER_PROJECT,
            'suggestions' => $this->keywords->suggestions($project),
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
        return '/projects/' . $project->publicId . '/keywords';
    }
}
