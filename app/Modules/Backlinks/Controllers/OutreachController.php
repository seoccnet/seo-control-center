<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Backlinks\OutreachService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Backlinks → Link outreach (1.8.0).
 */
final readonly class OutreachController
{
    public function __construct(
        private OutreachService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $status = $request->getQueryParams()['status'] ?? null;

        return $this->responses->html($this->views->render('backlinks/outreach', [
            'project' => $project,
            ...$this->service->page(WebContext::user($request), $project, is_string($status) ? $status : null),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $user = WebContext::user($request);
        $session = WebContext::session($request);
        $text = static fn(string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
        try {
            switch ($text('action')) {
                case 'remove':
                    $this->service->remove($user, $project, $text('domain'));
                    $session->flash('status', sprintf('%s removed from the outreach list.', $text('domain')));
                    break;
                case 'status':
                    $this->service->update($user, $project, $text('domain'), $text('status'), $text('note'));
                    $session->flash('status', sprintf('%s updated.', $text('domain')));
                    break;
                default:
                    $domain = $this->service->add($user, $project, $text('domain'), $text('source'), $text('page'), $text('target'));
                    $session->flash('status', sprintf('%s added to the outreach list.', $domain));
            }
        } catch (ValidationException $e) {
            $session->flash('error', implode(' ', $e->errors()));
        }

        return $this->responses->redirect('/projects/' . $project->publicId . '/backlinks/outreach');
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
