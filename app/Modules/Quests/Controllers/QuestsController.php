<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Quests\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Quests\QuestCatalogue;
use SeoControlCenter\Domain\Quests\QuestProgressRepository;
use SeoControlCenter\Domain\Workspaces\CurrentWorkspace;
use SeoControlCenter\Modules\Quests\GuideCatalogue;
use SeoControlCenter\Modules\Quests\QuestAccessFactory;
use SeoControlCenter\Modules\Quests\QuestLogs;
use SeoControlCenter\Modules\Quests\ShowMeTargets;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * The Quest Log (ADR-035). Pages are read-only apart from the user's own
 * progress; skipping and hiding are the only actions.
 */
final readonly class QuestsController
{
    public function __construct(
        private QuestLogs $logs,
        private QuestProgressRepository $progress,
        private QuestAccessFactory $access,
        private ShowMeTargets $targets,
        private GuideCatalogue $guide,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private Clock $clock,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $context = $request->getAttribute(RequestAttribute::WORKSPACE);
        if (!$context instanceof CurrentWorkspace) {
            return $this->responses->html($this->views->render('quests/index', ['log' => null], $request));
        }
        $user = WebContext::user($request);
        $workspace = $context->workspace();
        $projects = $this->logs->projects($user, $workspace);
        $choice = $request->getQueryParams()['project'] ?? null;
        $project = is_string($choice) && $choice !== ''
            ? $this->logs->choose(WebContext::session($request), $user, $workspace, $choice)
            : $this->logs->selected(WebContext::session($request), $workspace, $projects);
        $log = $this->logs->build($user, $workspace, $projects, $project);
        $key = $request->getQueryParams()['chapter'] ?? null;
        $chapter = is_string($key) ? QuestCatalogue::chapterByKey($key) : null;
        $number = $chapter->number ?? ($log->current !== null ? $log->current->quest->chapter : 1);

        return $this->responses->html($this->views->render('quests/index', [
            'log' => $log,
            'chapter' => $log->chapter($number) ?? $log->foundations(),
            'projects' => $projects,
            'project' => $project,
            'isAdmin' => $user->subject()->isSystemAdmin,
            'guide' => $this->guide,
        ], $request));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $context = WebContext::workspace($request);
        $user = WebContext::user($request);
        $workspace = $context->workspace();
        $projects = $this->logs->projects($user, $workspace);
        $project = $this->logs->selected(WebContext::session($request), $workspace, $projects);
        $log = $this->logs->build($user, $workspace, $projects, $project);
        $state = $log->quest(WebContext::routeParam($request, 'quest'));
        if ($state === null) {
            throw new HttpException(404, 'Quest not found.');
        }

        return $this->responses->html($this->views->render('quests/show', [
            'log' => $log,
            'state' => $state,
            'project' => $project,
            'targets' => $this->targets,
            'guide' => $this->guide,
            'assistant' => $this->access->assistant($user, $workspace),
            'isAdmin' => $user->subject()->isSystemAdmin,
        ], $request));
    }

    public function skip(ServerRequestInterface $request): ResponseInterface
    {
        return $this->changeSkip($request, true);
    }

    public function unskip(ServerRequestInterface $request): ResponseInterface
    {
        return $this->changeSkip($request, false);
    }

    /** Hide or show the Quest Log widget; the Quest Log page stays available. */
    public function visibility(ServerRequestInterface $request): ResponseInterface
    {
        $context = WebContext::workspace($request);
        $body = WebContext::body($request);
        $hidden = ($body['hidden'] ?? '') === '1';
        $this->progress->setHidden(WebContext::user($request)->id, $context->id(), $hidden);
        WebContext::session($request)->flash('status', $hidden ? 'The Quest Log is hidden from your dashboard. Open it any time from the navigation.' : 'The Quest Log is shown on your dashboard again.');
        $return = $body['return'] ?? null;

        return $this->responses->redirect(is_string($return) && preg_match('#^/(?![/\\\\])[A-Za-z0-9/_.?=&%-]*$#', $return) === 1 ? $return : '/quests');
    }

    public function guide(ServerRequestInterface $request): ResponseInterface
    {
        $id = WebContext::routeParam($request, 'chapter');
        $html = $this->guide->html($id);
        if ($html === null) {
            throw new HttpException(404, 'Guide chapter not found.');
        }

        return $this->responses->html($this->views->render('quests/guide', ['title' => $this->guide->title($id) ?? 'Guide', 'html' => $html, 'chapters' => $this->guide->titles(), 'current' => $id], $request));
    }

    /** The former Get started guide: Chapter 1 of the Quest Log, for the same project. */
    public function gettingStarted(ServerRequestInterface $request): ResponseInterface
    {
        $choice = $request->getQueryParams()['project'] ?? null;
        $context = $request->getAttribute(RequestAttribute::WORKSPACE);
        $query = 'chapter=' . QuestCatalogue::FOUNDATIONS;
        if (is_string($choice) && $choice !== '' && $context instanceof CurrentWorkspace) {
            // Resolving first keeps a foreign or invisible project a 404, as before.
            $project = $this->logs->choose(WebContext::session($request), WebContext::user($request), $context->workspace(), $choice);
            $query .= '&project=' . rawurlencode($project->publicId);
        }

        return $this->responses->redirect('/quests?' . $query, 302);
    }

    private function changeSkip(ServerRequestInterface $request, bool $skip): ResponseInterface
    {
        $context = WebContext::workspace($request);
        $user = WebContext::user($request);
        $workspace = $context->workspace();
        $key = WebContext::routeParam($request, 'quest');
        $projects = $this->logs->projects($user, $workspace);
        // Only checks visibility: new quests and rank toasts stay for the page the user lands on.
        $state = $this->logs->build($user, $workspace, $projects, $this->logs->selected(WebContext::session($request), $workspace, $projects), false)->quest($key);
        // Foundations setup steps can't be skipped; invisible quests don't exist for this user.
        if ($state === null || $state->setup !== null || $state->quest->weekly) {
            throw new HttpException(404, 'Quest not found.');
        }
        if ($skip) {
            $this->progress->skip($user->id, $workspace->id, $key, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        } else {
            $this->progress->unskip($user->id, $workspace->id, $key);
        }

        return $this->responses->redirect('/quests/' . $key);
    }
}
