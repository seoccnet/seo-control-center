<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Authorization;

/**
 * Supplies role data for authorization. Implemented by the workspace module.
 */
interface MembershipProvider
{
    /**
     * Active membership of the user in the workspace, or null.
     */
    public function workspaceMembership(int $userId, int $workspaceId): ?Membership;

    /**
     * Returns:
     * - null when the user is not a member of the project;
     * - a Membership whose roleKey is '' and permissions [] when the user is
     *   a member without a project-specific role (inherits the workspace role);
     * - otherwise the project role, which can only narrow permissions.
     */
    public function projectMembership(int $userId, int $projectId): ?Membership;
}
