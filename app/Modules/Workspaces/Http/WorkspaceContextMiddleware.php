<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Workspaces\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Logging\LogContext;
use SeoControlCenter\Core\Session\Session;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Workspaces\CurrentWorkspace;
use SeoControlCenter\Domain\Workspaces\WorkspaceMembershipView;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;

/**
 * Determines the workspace a signed-in user is working in.
 *
 * The chosen workspace's public ID is kept in the session, but membership is
 * re-checked on every request; if it is no longer valid the user falls back
 * to their first workspace (or none).
 *
 * A link to a project in another of the user's workspaces (from an alert
 * e-mail, a report or a bookmark) would answer 404 in the current one. When
 * such a GET request ends in 404 and the user may view the project in its own
 * workspace, the session switches there and the browser is sent back to the
 * same address (0.44.2, finding 40). Projects the user cannot see stay 404
 * (ADR-013).
 */
final readonly class WorkspaceContextMiddleware implements MiddlewareInterface
{
    public const string SESSION_KEY = 'workspace';

    public function __construct(
        private WorkspaceRepository $workspaces,
        private LogContext $logContext,
        private ?ProjectRepository $projects = null,
        private ?Authorizer $authorizer = null,
        private ?ResponseFactory $responses = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(RequestAttribute::USER);
        $session = $request->getAttribute(RequestAttribute::SESSION);

        if (!$user instanceof User || !$session instanceof Session) {
            return $handler->handle($request);
        }
        $all = $this->workspaces->forUser($user->id);
        if ($all === []) {
            return $handler->handle($request);
        }
        $selected = $session->get(self::SESSION_KEY);
        $current = $all[0];
        foreach ($all as $membership) {
            if ($membership->workspace->publicId === $selected) {
                $current = $membership;
                break;
            }
        }
        if ($selected !== $current->workspace->publicId) {
            $session->set(self::SESSION_KEY, $current->workspace->publicId);
        }
        $this->logContext->set('workspace_id', $current->workspace->id);
        $request = $request->withAttribute(RequestAttribute::WORKSPACE, new CurrentWorkspace($current, $all));

        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            if ($e->statusCode() === 404) {
                $redirect = $this->projectElsewhere($request, $user, $session, $current, $all);
                if ($redirect !== null) {
                    return $redirect;
                }
            }
            throw $e;
        }
    }

    /**
     * Switches to the workspace of a project the user may view, and redirects
     * back to the address; null when the request is not a project page, or the
     * project is not in another workspace the user can see it in.
     *
     * @param list<WorkspaceMembershipView> $all
     */
    private function projectElsewhere(ServerRequestInterface $request, User $user, Session $session, WorkspaceMembershipView $current, array $all): ?ResponseInterface
    {
        if ($this->projects === null || $this->authorizer === null || $this->responses === null || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }
        $path = $request->getUri()->getPath();
        if (preg_match('#^/projects/(' . Uuid::ROUTE_PATTERN . ')(?:/|$)#', $path, $match) !== 1) {
            return null;
        }
        foreach ($all as $membership) {
            if ($membership->workspace->id === $current->workspace->id) {
                continue;
            }
            $project = $this->projects->findInWorkspace($membership->workspace->id, $match[1]);
            if ($project === null || !$this->authorizer->canSeeProject($user->subject(), $membership->workspace->id, $project->id)) {
                continue;
            }
            $session->set(self::SESSION_KEY, $membership->workspace->publicId);
            $session->regenerate();
            $session->flash('status', sprintf('Switched to the workspace %s to open %s.', $membership->workspace->name, $project->name));
            $query = $request->getUri()->getQuery();

            return $this->responses->redirect($path . ($query !== '' ? '?' . $query : ''), 302);
        }

        return null;
    }
}
