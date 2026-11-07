<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Workspaces\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\Workspaces\WorkspaceService;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\Workspaces\Http\WorkspaceContextMiddleware;

/**
 * Workspace settings (rename, delete) and creating new workspaces.
 */
final readonly class WorkspaceSettingsController
{
    private const string SETTINGS = '/workspace/settings';

    public function __construct(
        private WorkspaceService $service,
        private Authorizer $authorizer,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->settings($request, [], [], 200);
    }

    public function rename(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';
        try {
            $this->service->rename(WebContext::user($request), WebContext::workspace($request)->workspace(), $name, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->settings($request, $e->errors(), $body, 422);
        }
        WebContext::session($request)->flash('status', 'Workspace renamed.');

        return $this->responses->redirect(self::SETTINGS);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        $context = WebContext::workspace($request);
        $workspace = $context->workspace();
        try {
            $this->service->delete(
                WebContext::user($request),
                $workspace,
                is_string($body['confirm'] ?? null) ? $body['confirm'] : '',
                $context->all,
                ClientInfo::fromRequest($request),
            );
        } catch (ValidationException $e) {
            return $this->settings($request, $e->errors(), [], 422);
        }
        $session = WebContext::session($request);
        $session->remove(WorkspaceContextMiddleware::SESSION_KEY);
        $session->regenerate();
        $session->flash('status', sprintf('Workspace "%s" was deleted.', $workspace->name));

        return $this->responses->redirect('/');
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizer->authorize(WebContext::user($request)->subject(), Permission::SystemSettingsManage);

        return $this->createForm($request, [], '', 200);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $name = WebContext::body($request)['name'] ?? '';
        $name = is_string($name) ? $name : '';
        try {
            $workspace = $this->service->create(WebContext::user($request), $name, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->createForm($request, $e->errors(), $name, 422);
        }
        $session = WebContext::session($request);
        $session->set(WorkspaceContextMiddleware::SESSION_KEY, $workspace->publicId);
        $session->regenerate();
        $session->flash('status', sprintf('Workspace "%s" created. You are its owner; add members under Workspace → Members.', $workspace->name));

        return $this->responses->redirect('/');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function settings(ServerRequestInterface $request, array $errors, array $old, int $status): ResponseInterface
    {
        $user = WebContext::user($request);
        $context = WebContext::workspace($request);
        $workspace = $context->workspace();
        $this->authorizer->authorize($user->subject(), Permission::WorkspaceView, $workspace->id);

        return $this->responses->html($this->views->render('workspace/settings', [
            'workspace' => $workspace,
            'canRename' => $this->authorizer->can($user->subject(), Permission::WorkspaceManage, $workspace->id),
            'canDelete' => $this->authorizer->can($user->subject(), Permission::WorkspaceDelete, $workspace->id),
            'deleteProblem' => $this->service->deleteProblem($workspace, $context->all),
            'errors' => $errors,
            'old' => $old,
        ], $request), $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function createForm(ServerRequestInterface $request, array $errors, string $name, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('workspace/new', [
            'errors' => $errors,
            'name' => $name,
        ], $request), $status);
    }
}
