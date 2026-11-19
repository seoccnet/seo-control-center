<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Snippets\TitleRewrite;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\SiteAudit\TitleRewriteService;

/**
 * Project → Site audit → Titles Google rewrites (1.7.0). Read-only.
 */
final readonly class TitleRewriteController
{
    public const array KINDS = [TitleRewrite::REWRITTEN, TitleRewrite::MISSING, TitleRewrite::SITE_NAME, TitleRewrite::CUT];

    public function __construct(
        private TitleRewriteService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $kind = $request->getQueryParams()['kind'] ?? null;
        $kind = is_string($kind) && in_array($kind, self::KINDS, true) ? $kind : null;

        return $this->responses->html($this->views->render('site-audit/title-rewrites', [
            'project' => $project,
            'kind' => $kind,
            ...$this->service->report($project, $kind),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }
}
