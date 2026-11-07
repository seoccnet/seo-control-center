<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentBriefs\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\ContentBriefs\ClaimService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/** Claims and sources on a content brief (ADR-042). */
final readonly class ClaimsController
{
    public function __construct(private ProjectService $projects, private ClaimService $claims, private ResponseFactory $responses) {}

    public function add(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $brief = WebContext::routeParam($request, 'brief');
        $this->claims->add(WebContext::user($request), $project, $brief, WebContext::body($request), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Claim added. A person still has to give the verdict.');

        return $this->back($project, $brief);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $brief = WebContext::routeParam($request, 'brief');
        $before = $this->claims->claim($project, WebContext::routeParam($request, 'claim'));
        $claim = $this->claims->update(WebContext::user($request), $project, $before->publicId, WebContext::body($request), ClientInfo::fromRequest($request));
        $reset = $claim->status === 'unreviewed' && $before->status !== 'unreviewed' && ((WebContext::body($request)['status'] ?? null) === $before->status);
        WebContext::session($request)->flash('status', $reset ? 'Claim saved. The evidence changed, so the earlier verdict was reset to Unreviewed.' : 'Claim saved.');

        return $this->back($project, $brief);
    }

    public function remove(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $brief = WebContext::routeParam($request, 'brief');
        $this->claims->remove(WebContext::user($request), $project, WebContext::routeParam($request, 'claim'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Claim removed.');

        return $this->back($project, $brief);
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }

    private function back(Project $project, string $brief): ResponseInterface
    {
        return $this->responses->redirect('/projects/' . $project->publicId . '/content-briefs/' . $brief . '#claims');
    }
}
