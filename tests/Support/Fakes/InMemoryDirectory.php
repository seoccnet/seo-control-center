<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Authorization\Membership;
use SeoControlCenter\Core\Authorization\MembershipProvider;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Accounts\Email;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Accounts\UserCredentials;
use SeoControlCenter\Domain\Accounts\UserOverview;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Workspaces\MemberView;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Domain\Workspaces\WorkspaceMembershipView;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;

/**
 * In-memory users, workspaces, memberships and projects (see
 * InMemoryProjectRepository) for HTTP tests that
 * run without a database. Mirrors the role/permission seed of migration 000004.
 * The same rules are verified against a real database in tests/Integration.
 */
final class InMemoryDirectory implements UserRepository, WorkspaceRepository, MembershipProvider
{
    public const array ROLE_PERMISSIONS = [
        'owner' => ['workspace.view', 'workspace.manage', 'workspace.members.manage', 'workspace.delete', 'workspace.transfer',
            'projects.view', 'projects.create', 'projects.edit', 'projects.delete', 'integrations.manage', 'analysis.execute', 'audit.view', 'tokens.manage'],
        'admin' => ['workspace.view', 'workspace.manage', 'workspace.members.manage',
            'projects.view', 'projects.create', 'projects.edit', 'projects.delete', 'integrations.manage', 'analysis.execute', 'audit.view', 'tokens.manage'],
        'member' => ['workspace.view', 'projects.view', 'projects.edit', 'analysis.execute'],
        'viewer' => ['workspace.view', 'projects.view'],
    ];

    /** @var array<int, array{user: User, hash: string}> */
    public array $users = [];

    /** @var array<int, Workspace> */
    public array $workspaces = [];

    /** @var array<int, array<int, string>> workspaceId => userId => roleKey */
    public array $workspaceRoles = [];

    /** @var array<int, array<int, ?string>> projectId => userId => roleKey|null */
    public array $projectRoles = [];

    /** @var array<int, Project> */
    public array $projects = [];

    /** @var array<int, true> project ID => deleted */
    public array $deleted = [];

    /** @var array<int, true> workspace ID => deleted */
    public array $deletedWorkspaces = [];

    private int $nextId = 1;

    public function nextId(): int
    {
        return $this->nextId++;
    }

    // ---- setup helpers ----------------------------------------------------

    public function addUser(string $email, string $passwordHash, SystemRole $role = SystemRole::None, bool $active = true): User
    {
        $user = new User($this->nextId++, Uuid::v7(), $email, ucfirst(strtok($email, '@') ?: 'user'), $role, $active);
        $this->users[$user->id] = ['user' => $user, 'hash' => $passwordHash];

        return $user;
    }

    public function addWorkspace(string $name): Workspace
    {
        $workspace = new Workspace($this->nextId++, Uuid::v7(), $name);
        $this->workspaces[$workspace->id] = $workspace;

        return $workspace;
    }

    public function joinWorkspace(Workspace $workspace, User $user, string $roleKey): void
    {
        $this->workspaceRoles[$workspace->id][$user->id] = $roleKey;
    }

    public function addProject(Workspace $workspace, string $name, string $domain = 'example.com'): Project
    {
        $id = $this->nextId++;
        $project = new Project($id, Uuid::v7(), $workspace->id, $name, $domain, 'UTC', '2026-09-26 10:00:00', '2026-09-26 10:00:00');
        $this->projects[$id] = $project;

        return $project;
    }

    public function addProjectMember(Project $project, User $user, ?string $roleKey = null): void
    {
        $this->projectRoles[$project->id][$user->id] = $roleKey;
    }

    // ---- UserRepository ---------------------------------------------------

    public function findById(int $id): ?User
    {
        return $this->users[$id]['user'] ?? null;
    }

    public function findByEmail(Email $email): ?User
    {
        return $this->credentialsByEmail($email)?->user;
    }

    public function credentialsByEmail(Email $email): ?UserCredentials
    {
        foreach ($this->users as $row) {
            if ($row['user']->email === $email->value) {
                return new UserCredentials($row['user'], $row['hash']);
            }
        }

        return null;
    }

    public function create(Email $email, string $name, string $passwordHash, SystemRole $systemRole): User
    {
        $user = new User($this->nextId++, Uuid::v7(), $email->value, $name, $systemRole, true);
        $this->users[$user->id] = ['user' => $user, 'hash' => $passwordHash];

        return $user;
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $this->users[$userId]['hash'] = $passwordHash;
    }

    public function updateProfile(int $userId, string $name, Email $email): void
    {
        $user = $this->users[$userId]['user'];
        $this->users[$userId]['user'] = new User($user->id, $user->publicId, $email->value, $name, $user->systemRole, $user->active);
    }

    public function recordLogin(int $userId): void {}

    /** @var array<int, true> user ID => two-factor enabled (for System → Users) */
    public array $mfaUsers = [];

    public function overview(): array
    {
        $result = [];
        foreach ($this->users as $id => $row) {
            $names = [];
            foreach ($this->workspaceRoles as $workspaceId => $members) {
                if (isset($members[$id]) && !isset($this->deletedWorkspaces[$workspaceId])) {
                    $names[] = $this->workspaces[$workspaceId]->name;
                }
            }
            sort($names);
            $result[] = new UserOverview($row['user'], null, isset($this->mfaUsers[$id]), $names);
        }

        return $result;
    }

    public function setActive(int $userId, bool $active): void
    {
        $u = $this->users[$userId]['user'];
        $this->users[$userId]['user'] = new User($u->id, $u->publicId, $u->email, $u->name, $u->systemRole, $active);
    }

    public function setSystemRole(int $userId, SystemRole $role): void
    {
        $u = $this->users[$userId]['user'];
        $this->users[$userId]['user'] = new User($u->id, $u->publicId, $u->email, $u->name, $role, $u->active);
    }

    public function countActiveAdmins(): int
    {
        return count(array_filter($this->users, static fn(array $row): bool => $row['user']->active && $row['user']->systemRole === SystemRole::Admin));
    }

    // ---- WorkspaceRepository ----------------------------------------------

    public function countAll(): int
    {
        return count(array_diff_key($this->workspaces, $this->deletedWorkspaces));
    }

    public function forUser(int $userId): array
    {
        $result = [];
        foreach ($this->workspaceRoles as $workspaceId => $members) {
            if (isset($members[$userId]) && !isset($this->deletedWorkspaces[$workspaceId])) {
                $result[] = new WorkspaceMembershipView($this->workspaces[$workspaceId], $members[$userId], ucfirst($members[$userId]));
            }
        }
        usort($result, static fn($a, $b): int => strcmp($a->workspace->name, $b->workspace->name));

        return $result;
    }

    public function createWithOwner(string $name, int $ownerUserId): Workspace
    {
        $workspace = $this->addWorkspace($name);
        $this->workspaceRoles[$workspace->id][$ownerUserId] = 'owner';

        return $workspace;
    }

    public function members(int $workspaceId): array
    {
        $result = [];
        foreach ($this->workspaceRoles[$workspaceId] ?? [] as $userId => $role) {
            $user = $this->users[$userId]['user'];
            $result[] = new MemberView($user->publicId, $user->name, $user->email, ucfirst($role), 'active', '2026-09-26 10:00:00', $userId, $role);
        }

        return $result;
    }

    public function addMember(int $workspaceId, int $userId, string $roleKey): bool
    {
        if (isset($this->workspaceRoles[$workspaceId][$userId])) {
            return false;
        }
        $this->workspaceRoles[$workspaceId][$userId] = $roleKey;

        return true;
    }

    public function changeRole(int $workspaceId, int $userId, string $roleKey): bool
    {
        if (!isset($this->workspaceRoles[$workspaceId][$userId])) {
            return false;
        }
        $this->workspaceRoles[$workspaceId][$userId] = $roleKey;

        return true;
    }

    public function removeMember(int $workspaceId, int $userId): bool
    {
        if (!isset($this->workspaceRoles[$workspaceId][$userId])) {
            return false;
        }
        unset($this->workspaceRoles[$workspaceId][$userId]);
        foreach ($this->projects as $project) {
            if ($project->workspaceId === $workspaceId) {
                unset($this->projectRoles[$project->id][$userId]);
            }
        }

        return true;
    }

    public function rename(int $workspaceId, string $name): void
    {
        if (isset($this->workspaces[$workspaceId]) && !isset($this->deletedWorkspaces[$workspaceId])) {
            $this->workspaces[$workspaceId] = new Workspace($workspaceId, $this->workspaces[$workspaceId]->publicId, $name);
        }
    }

    public function softDelete(int $workspaceId): void
    {
        $this->deletedWorkspaces[$workspaceId] = true;
        foreach ($this->projects as $project) {
            if ($project->workspaceId === $workspaceId) {
                $this->deleted[$project->id] = true;
            }
        }
    }

    // ---- MembershipProvider -----------------------------------------------

    public function workspaceMembership(int $userId, int $workspaceId): ?Membership
    {
        $role = $this->workspaceRoles[$workspaceId][$userId] ?? null;
        if ($role === null || isset($this->deletedWorkspaces[$workspaceId]) || !isset($this->users[$userId]) || !$this->users[$userId]['user']->active) {
            return null;
        }

        return new Membership($role, self::ROLE_PERMISSIONS[$role]);
    }

    public function projectMembership(int $userId, int $projectId): ?Membership
    {
        if (!array_key_exists($userId, $this->projectRoles[$projectId] ?? [])) {
            return null;
        }
        $role = $this->projectRoles[$projectId][$userId];

        return $role === null ? new Membership('', []) : new Membership($role, self::ROLE_PERMISSIONS[$role]);
    }
}
