<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Investigations\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Investigations\InvestigationService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Research → Investigations: the list, one investigation with its entries,
 * and the forms that change them.
 */
final readonly class InvestigationsController
{
    public function __construct(private ProjectService $projects, private InvestigationService $investigations, private ResponseFactory $responses, private ViewRenderer $views) {}

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function index(ServerRequestInterface $request, array $errors = [], array $old = [], int $status = 200): ResponseInterface
    {
        $project = $this->project($request);
        $query = $request->getQueryParams();
        $filter = is_string($query['status'] ?? null) ? $query['status'] : null;
        $list = $this->investigations->list($project, $filter);

        return $this->responses->html($this->views->render('investigations/index', [
            'project' => $project,
            'investigations' => $list['items'],
            'total' => $list['total'],
            'filter' => $filter,
            'canEdit' => $this->investigations->canEdit(WebContext::user($request), $project),
            'errors' => $errors,
            'old' => $old,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);
        try {
            $saved = $this->investigations->save(WebContext::user($request), $project, $body, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->index($request, $e->errors(), $body, 422);
        }
        WebContext::session($request)->flash('status', 'Investigation started. Add the sources, checks and limitations as you go.');

        return $this->responses->redirect($this->path($project, $saved->publicId));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function show(ServerRequestInterface $request, array $errors = [], array $old = [], int $status = 200): ResponseInterface
    {
        $project = $this->project($request);
        $investigation = $this->investigations->get($project, $this->id($request));

        return $this->responses->html($this->views->render('investigations/show', [
            'project' => $project,
            'investigation' => $investigation,
            'canEdit' => $this->investigations->canEdit(WebContext::user($request), $project),
            'choices' => $this->investigations->choices($project),
            'errors' => $errors,
            'old' => $old,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $id = $this->id($request);
        $body = WebContext::body($request);
        $version = is_string($body['version'] ?? null) && ctype_digit($body['version']) ? (int) $body['version'] : null;
        try {
            $this->investigations->save(WebContext::user($request), $project, $body, ClientInfo::fromRequest($request), $id, $version);
        } catch (ValidationException $e) {
            return $this->show($request, $e->errors(), $body, 422);
        } catch (HttpException $e) {
            if ($e->statusCode() !== 409) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());

            return $this->responses->redirect($this->path($project, $id));
        }
        WebContext::session($request)->flash('status', 'Investigation saved.');

        return $this->responses->redirect($this->path($project, $id));
    }

    /** Adds an entry by hand, a finished analysis or a lookup on file. */
    public function addItem(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $id = $this->id($request);
        $body = WebContext::body($request);
        $user = WebContext::user($request);
        $client = ClientInfo::fromRequest($request);
        $from = is_string($body['from'] ?? null) ? $body['from'] : 'manual';
        try {
            if ($from === 'analysis') {
                $this->investigations->addAnalysis($user, $project, $id, is_string($body['analysis_id'] ?? null) ? $body['analysis_id'] : '', $client);
            } elseif ($from === 'lookup') {
                [$kind, $subject] = array_pad(explode('|', is_string($body['lookup'] ?? null) ? $body['lookup'] : '', 2), 2, '');
                $this->investigations->addLookup($user, $project, $id, $kind, $subject !== '' ? $subject : null, $client);
            } else {
                $url = is_string($body['url'] ?? null) ? trim($body['url']) : '';
                $this->investigations->addItems($user, $project, $id, [[
                    'kind' => $body['kind'] ?? null,
                    'label' => $body['label'] ?? null,
                    'detail' => $body['detail'] ?? null,
                    'observed_on' => $body['observed_on'] ?? null,
                    'ref_type' => $url !== '' ? 'url' : null,
                    'ref_id' => $url !== '' ? $url : null,
                ]], $client);
            }
        } catch (ValidationException $e) {
            return $this->show($request, $e->errors(), $body, 422);
        }
        WebContext::session($request)->flash('status', 'Added to the investigation.');

        return $this->responses->redirect($this->path($project, $id) . '#entries');
    }

    public function removeItem(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $id = $this->id($request);
        $item = WebContext::routeParam($request, 'item');
        $this->investigations->removeItem(WebContext::user($request), $project, $id, $item, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Entry removed.');

        return $this->responses->redirect($this->path($project, $id) . '#entries');
    }

    /** From an analysis page: add it to an investigation, or start one with it. */
    public function fromAnalysis(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);
        $user = WebContext::user($request);
        $client = ClientInfo::fromRequest($request);
        $analysisId = is_string($body['analysis_id'] ?? null) && Uuid::isValid($body['analysis_id']) ? $body['analysis_id'] : throw new HttpException(404, 'Finished analysis not found.');
        $target = is_string($body['investigation'] ?? null) ? $body['investigation'] : '';
        if ($target === 'new') {
            $title = is_string($body['title'] ?? null) && trim($body['title']) !== '' ? $body['title'] : 'Why competitors outrank us';
            $target = $this->investigations->save($user, $project, ['title' => mb_substr($title, 0, 200)], $client)->publicId;
        } elseif (!Uuid::isValid($target)) {
            throw new HttpException(404, 'Investigation not found.');
        }
        $this->investigations->addAnalysis($user, $project, $target, $analysisId, $client);
        WebContext::session($request)->flash('status', 'The analysis, its sources, checks and limitations were added.');

        return $this->responses->redirect($this->path($project, $target) . '#entries');
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }

    private function id(ServerRequestInterface $request): string
    {
        return WebContext::routeParam($request, 'investigation');
    }

    private function path(Project $project, string $id): string
    {
        return '/projects/' . $project->publicId . '/investigations/' . $id;
    }
}
