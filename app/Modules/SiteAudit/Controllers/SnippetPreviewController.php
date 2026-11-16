<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\SiteAudit\SnippetPreviewService;

/**
 * Project → Site audit → Snippet preview (1.6.0). A GET page: nothing is stored.
 */
final readonly class SnippetPreviewController
{
    public function __construct(
        private SnippetPreviewService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $query = $request->getQueryParams();
        $text = static fn(string $key, int $max): string => is_string($query[$key] ?? null) ? mb_substr(trim($query[$key]), 0, $max) : '';
        $trial = ($query['try'] ?? null) === '1'
            ? ['url' => $text('url', 2048), 'title' => $text('title', 300), 'description' => $text('description', 1000)]
            : null;

        return $this->responses->html($this->views->render('site-audit/snippets', [
            'project' => $project,
            'cutOnly' => ($query['cut'] ?? null) === '1',
            ...$this->service->preview($project, ($query['cut'] ?? null) === '1', $trial),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }
}
