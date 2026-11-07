<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Workspaces;

interface WorkspaceRepository
{
    /**
     * Active workspace memberships of a user, ordered by workspace name. Deleted workspaces are left out.
     *
     * @return list<WorkspaceMembershipView>
     */
    public function forUser(int $userId): array;

    /**
     * Creates a workspace and makes the user its owner.
     */
    public function createWithOwner(string $name, int $ownerUserId): Workspace;

    /**
     * Workspaces of the installation that are not deleted (installation-wide).
     */
    public function countAll(): int;

    /**
     * @return list<MemberView>
     */
    public function members(int $workspaceId): array;

    /**
     * Adds a user with a system role (owner, admin, member, viewer); false if already a member.
     */
    public function addMember(int $workspaceId, int $userId, string $roleKey): bool;

    public function changeRole(int $workspaceId, int $userId, string $roleKey): bool;

    public function removeMember(int $workspaceId, int $userId): bool;

    public function rename(int $workspaceId, string $name): void;

    /**
     * Soft-deletes the workspace and its projects: hidden everywhere, excluded
     * from access checks and scheduled work; data is kept.
     */
    public function softDelete(int $workspaceId): void;
}
