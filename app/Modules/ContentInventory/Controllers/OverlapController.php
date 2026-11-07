<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentInventory\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\ContentInventory\OverlapFinder;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\ContentInventory\OverlapService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/** Research → Content inventory → Overlap review (ADR-042). */
final readonly class OverlapController
{
    public function __construct(private ProjectService $projects, private OverlapService $overlap, private ResponseFactory $responses, private ViewRenderer $views) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $kind = $request->getQueryParams()['kind'] ?? '';
        $kind = is_string($kind) && isset(OverlapFinder::KINDS[$kind]) ? $kind : '';

        return $this->responses->html($this->views->render('inventory/overlap', ['project' => $project, 'kind' => $kind, ...$this->overlap->overview(WebContext::user($request), $project, $kind)], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function decide(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);
        $text = static fn(string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
        $retained = $text('retained') !== '' ? $text('retained') : null;
        $version = ctype_digit($text('version')) ? (int) $text('version') : 0;
        $this->overlap->decide(WebContext::user($request), $project, WebContext::routeParam($request, 'candidate'), $text('decision'), $retained, $text('note'), $version, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Decision saved. SEOcc changes nothing on your site; carry out a planned merge in your CMS and add a change note.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/inventory/overlap#c-' . substr(WebContext::routeParam($request, 'candidate'), 0, 12));
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
