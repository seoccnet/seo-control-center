<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Workspaces\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\Workspaces\MemberService;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\Workspaces\Http\WorkspaceContextMiddleware;

/**
 * Workspace switching and members. A new invitation link is rendered once
 * in the response to its creation, never stored or flashed.
 */
final readonly class WorkspaceController
{
    private const string MEMBERS = '/workspace/members';

    public function __construct(
        private WorkspaceRepository $workspaces,
        private MemberService $members,
        private Authorizer $authorizer,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    /**
     * Switches the current workspace. Only workspaces the user belongs to are accepted.
     */
    public function switch(ServerRequestInterface $request): ResponseInterface
    {
        $context = WebContext::workspace($request);
        $target = WebContext::body($request)['workspace'] ?? null;

        foreach ($context->all as $membership) {
            if ($membership->workspace->publicId === $target) {
                $session = WebContext::session($request);
                $session->set(WorkspaceContextMiddleware::SESSION_KEY, $target);
                $session->regenerate();

                return $this->responses->redirect('/');
            }
        }

        throw new NotFoundHttpException('Workspace not found.');
    }

    public function members(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, [], [], null, 200);
    }

    public function invite(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        try {
            $result = $this->members->invite(WebContext::user($request), WebContext::workspace($request)->workspace(), $body, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors(), $body, null, 422);
        }
        if ($result['member'] !== null) {
            WebContext::session($request)->flash('status', sprintf('%s already had an account and was added to the workspace.%s', $result['member']->email, $result['mailed'] ? ' They were notified by e-mail.' : ''));

            return $this->responses->redirect(self::MEMBERS);
        }

        return $this->page($request, [], [], $result['link'], 201, $result['mailed'] ? $result['invitation']?->email : null);
    }

    public function changeRole(ServerRequestInterface $request): ResponseInterface
    {
        $role = WebContext::body($request)['role'] ?? '';
        try {
            $member = $this->members->changeRole(
                WebContext::user($request),
                WebContext::workspace($request)->workspace(),
                WebContext::routeParam($request, 'user'),
                is_string($role) ? $role : '',
                ClientInfo::fromRequest($request),
            );
            WebContext::session($request)->flash('status', sprintf('Role of %s updated.', $member->name));
        } catch (ValidationException $e) {
            WebContext::session($request)->flash('error', implode(' ', $e->errors()));
        }

        return $this->responses->redirect(self::MEMBERS);
    }

    public function remove(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $member = $this->members->remove(
                WebContext::user($request),
                WebContext::workspace($request)->workspace(),
                WebContext::routeParam($request, 'user'),
                ClientInfo::fromRequest($request),
            );
            WebContext::session($request)->flash('status', sprintf('%s was removed from the workspace.', $member->name));
        } catch (ValidationException $e) {
            WebContext::session($request)->flash('error', implode(' ', $e->errors()));
        }

        return $this->responses->redirect(self::MEMBERS);
    }

    public function revokeInvitation(ServerRequestInterface $request): ResponseInterface
    {
        $this->members->revokeInvitation(
            WebContext::user($request),
            WebContext::workspace($request)->workspace(),
            WebContext::routeParam($request, 'invitation'),
            ClientInfo::fromRequest($request),
        );
        WebContext::session($request)->flash('status', 'Invitation revoked. Its link no longer works.');

        return $this->responses->redirect(self::MEMBERS);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function page(ServerRequestInterface $request, array $errors, array $old, ?string $newLink, int $status, ?string $mailedTo = null): ResponseInterface
    {
        $user = WebContext::user($request);
        $workspace = WebContext::workspace($request)->workspace();
        $this->authorizer->authorize($user->subject(), Permission::WorkspaceView, $workspace->id);
        $canManage = $this->members->canManage($user, $workspace);

        return $this->responses->html($this->views->render('workspace/members', [
            'members' => $this->workspaces->members($workspace->id),
            'invitations' => $canManage ? $this->members->pendingInvitations($user, $workspace) : [],
            'canManage' => $canManage,
            'currentUserId' => $user->id,
            'roles' => MemberService::ASSIGNABLE_ROLES,
            'invitationDays' => MemberService::INVITATION_DAYS,
            'newLink' => $newLink,
            'mailedTo' => $mailedTo,
            'errors' => $errors,
            'old' => $old,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }
}
