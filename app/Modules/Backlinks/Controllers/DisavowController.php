<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Backlinks\DisavowService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Backlinks → Disavow file (1.7.0).
 */
final readonly class DisavowController
{
    public function __construct(
        private DisavowService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, $this->load($request));
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $domains = array_values(array_filter((array) ($body['domains'] ?? []), is_string(...)));
        $user = WebContext::user($request);
        $client = ClientInfo::fromRequest($request);
        if (($body['action'] ?? '') === 'remove') {
            $removed = $this->service->remove($user, $project, $domains, $client);
            WebContext::session($request)->flash('status', sprintf('%d domain%s removed from the disavow list.', $removed, $removed === 1 ? '' : 's'));
        } else {
            try {
                $added = $this->service->add($user, $project, $domains, is_string($body['typed'] ?? null) ? mb_substr($body['typed'], 0, 100000) : '', $client);
            } catch (ValidationException $e) {
                return $this->page($request, $project, $e->errors(), 422);
            }
            WebContext::session($request)->flash('status', sprintf('%d domain%s added to the disavow list. Download the file and upload it to Google when you are ready.', $added, $added === 1 ? '' : 's'));
        }

        return $this->responses->redirect('/projects/' . $project->publicId . '/backlinks/disavow');
    }

    public function file(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $name = 'disavow-' . (preg_replace('/[^a-z0-9.-]/', '', strtolower($project->primaryDomain)) ?: 'site') . '.txt';

        return $this->responses->text($this->service->file($project))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** @param array<string, string> $errors */
    private function page(ServerRequestInterface $request, Project $project, array $errors = [], int $status = 200): ResponseInterface
    {
        return $this->responses->html($this->views->render('backlinks/disavow', [
            'project' => $project,
            'errors' => $errors,
            ...$this->service->page(WebContext::user($request), $project, ($request->getQueryParams()['all'] ?? null) === '1'),
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
