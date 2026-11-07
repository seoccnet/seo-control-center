<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\SiteAudit\LinkSuggestionService;

/**
 * Improve → Internal links (ADR-040, F1): link suggestions and the links
 * into and out of one page, from the newest completed site audit.
 */
final readonly class InternalLinksController
{
    public function __construct(private LinkSuggestionService $links, private ProjectService $projects, private ResponseFactory $responses, private ViewRenderer $views) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $query = $request->getQueryParams();
        $search = is_string($query['q'] ?? null) ? $query['q'] : '';
        $url = is_string($query['url'] ?? null) ? mb_substr(trim($query['url']), 0, 2048) : '';
        $number = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);

        return $this->responses->html($this->views->render('site-audit/links', [
            'project' => $project,
            'search' => mb_substr(trim($search), 0, 200),
            'url' => $url,
            'lookup' => $url !== '' ? $this->links->pageLinks($project, $url) : null,
            ...$this->links->suggestions($project, $search, $number === false ? 1 : $number),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }
}
