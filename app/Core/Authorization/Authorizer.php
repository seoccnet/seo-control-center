<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Authorization;

use SeoControlCenter\Core\Http\Exception\HttpException;

/**
 * Central permission checks (ADR-013). Controllers, services, MCP tools and
 * jobs all authorize through this class; nothing inspects role names directly.
 *
 * effective(user, project) =
 *     workspace role permissions
 *     ∩ project role permissions (if a project role is set)
 *     — and nothing if the workspace role is not owner/admin and the user
 *       is not a member of the project.
 */
final class Authorizer
{
    /** @var array<string, ?Membership> */
    private array $cache = [];

    public function __construct(private readonly MembershipProvider $memberships) {}

    public function can(Subject $subject, Permission $permission, ?int $workspaceId = null, ?int $projectId = null): bool
    {
        if ($permission->isSystem()) {
            return $subject->isSystemAdmin;
        }
        if ($workspaceId === null) {
            return false;
        }

        $workspace = $this->workspaceMembership($subject->userId, $workspaceId);
        if ($workspace === null || !$workspace->grants($permission)) {
            return false;
        }
        if ($projectId === null) {
            return true;
        }

        $project = $this->projectMembership($subject->userId, $projectId);
        if ($project === null) {
            return $workspace->coversAllProjects();
        }
        if ($project->roleKey === '') {
            return true; // member of the project, inherits the workspace role
        }

        return $project->grants($permission);
    }

    /**
     * @throws HttpException 403 when not allowed
     */
    public function authorize(Subject $subject, Permission $permission, ?int $workspaceId = null, ?int $projectId = null): void
    {
        if (!$this->can($subject, $permission, $workspaceId, $projectId)) {
            throw new HttpException(403, 'You do not have permission to do this.');
        }
    }

    /**
     * True when the user can see the project at all. Used to answer 404
     * rather than 403 for projects the user must not know about.
     */
    public function canSeeProject(Subject $subject, int $workspaceId, int $projectId): bool
    {
        return $this->can($subject, Permission::ProjectsView, $workspaceId, $projectId);
    }

    public function workspaceMembership(int $userId, int $workspaceId): ?Membership
    {
        $key = "w:{$userId}:{$workspaceId}";
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->memberships->workspaceMembership($userId, $workspaceId);
        }

        return $this->cache[$key];
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function projectMembership(int $userId, int $projectId): ?Membership
    {
        $key = "p:{$userId}:{$projectId}";
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->memberships->projectMembership($userId, $projectId);
        }

        return $this->cache[$key];
    }
}
