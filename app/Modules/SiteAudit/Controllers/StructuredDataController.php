<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\SiteAudit\StructuredDataService;

/**
 * Project → Site audit → Structured data generator (1.5.0). A GET form:
 * nothing is stored.
 */
final readonly class StructuredDataController
{
    public function __construct(
        private StructuredDataService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $query = $request->getQueryParams();
        $fields = is_array($query['f'] ?? null) ? $query['f'] : [];

        return $this->responses->html($this->views->render('site-audit/schema', [
            'project' => $project,
            ...$this->service->form(
                $project,
                is_string($query['url'] ?? null) ? trim($query['url']) : '',
                is_string($query['type'] ?? null) ? $query['type'] : '',
                $fields,
                ($query['generate'] ?? null) === '1',
            ),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }
}
