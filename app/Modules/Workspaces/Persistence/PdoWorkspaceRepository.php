<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Workspaces\Persistence;

use RuntimeException;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Workspaces\MemberView;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Domain\Workspaces\WorkspaceMembershipView;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;

final readonly class PdoWorkspaceRepository implements WorkspaceRepository
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function countAll(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM workspaces WHERE deleted_at IS NULL');
    }

    public function forUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT w.id, w.public_id, w.name, r.role_key, r.label
               FROM workspace_users wu
               JOIN workspaces w ON w.id = wu.workspace_id
               JOIN roles r ON r.id = wu.role_id
              WHERE wu.user_id = ? AND wu.status = 'active' AND w.deleted_at IS NULL
              ORDER BY w.name, w.id",
            [$userId],
        );

        return array_map(static fn(array $row): WorkspaceMembershipView => new WorkspaceMembershipView(
            new Workspace((int) $row['id'], (string) $row['public_id'], (string) $row['name']),
            (string) $row['role_key'],
            (string) $row['label'],
        ), $rows);
    }

    public function createWithOwner(string $name, int $ownerUserId): Workspace
    {
        return $this->db->transaction(function (Connection $db) use ($name, $ownerUserId): Workspace {
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            $publicId = Uuid::v7($this->clock);
            $db->execute('INSERT INTO workspaces (public_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)', [$publicId, $name, $now, $now]);
            $workspaceId = $db->lastInsertId();

            $ownerRole = $db->fetchValue("SELECT id FROM roles WHERE workspace_id IS NULL AND role_key = 'owner'");
            if ($ownerRole === null) {
                throw new RuntimeException('System role "owner" is missing. Run the migrations.');
            }
            $db->execute(
                "INSERT INTO workspace_users (workspace_id, user_id, role_id, status, created_at) VALUES (?, ?, ?, 'active', ?)",
                [$workspaceId, $ownerUserId, (int) $ownerRole, $now],
            );

            return new Workspace($workspaceId, $publicId, $name);
        });
    }

    public function members(int $workspaceId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT u.id AS user_id, u.public_id, u.name, u.email, r.role_key, r.label, wu.status, wu.created_at
               FROM workspace_users wu
               JOIN users u ON u.id = wu.user_id
               JOIN roles r ON r.id = wu.role_id
              WHERE wu.workspace_id = ?
              ORDER BY u.name, u.id',
            [$workspaceId],
        );

        return array_map(static fn(array $row): MemberView => new MemberView(
            (string) $row['public_id'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['label'],
            (string) $row['status'],
            (string) $row['created_at'],
            (int) $row['user_id'],
            (string) $row['role_key'],
        ), $rows);
    }

    public function addMember(int $workspaceId, int $userId, string $roleKey): bool
    {
        return $this->db->execute(
            "INSERT IGNORE INTO workspace_users (workspace_id, user_id, role_id, status, created_at)
             SELECT ?, ?, id, 'active', ? FROM roles WHERE workspace_id IS NULL AND role_key = ?",
            [$workspaceId, $userId, $this->clock->now()->format('Y-m-d H:i:s'), $roleKey],
        ) === 1;
    }

    public function changeRole(int $workspaceId, int $userId, string $roleKey): bool
    {
        return $this->db->execute(
            'UPDATE workspace_users SET role_id = (SELECT id FROM roles WHERE workspace_id IS NULL AND role_key = ?)
              WHERE workspace_id = ? AND user_id = ?',
            [$roleKey, $workspaceId, $userId],
        ) === 1;
    }

    public function removeMember(int $workspaceId, int $userId): bool
    {
        return $this->db->transaction(static function (Connection $db) use ($workspaceId, $userId): bool {
            // Project-level narrowing would otherwise apply again if the user is re-added later.
            $db->execute(
                'DELETE pm FROM project_members pm JOIN projects p ON p.id = pm.project_id WHERE p.workspace_id = ? AND pm.user_id = ?',
                [$workspaceId, $userId],
            );

            return $db->execute('DELETE FROM workspace_users WHERE workspace_id = ? AND user_id = ?', [$workspaceId, $userId]) === 1;
        });
    }

    public function rename(int $workspaceId, string $name): void
    {
        $this->db->execute(
            'UPDATE workspaces SET name = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL',
            [$name, $this->clock->now()->format('Y-m-d H:i:s'), $workspaceId],
        );
    }

    public function softDelete(int $workspaceId): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->db->transaction(static function (Connection $db) use ($workspaceId, $now): void {
            $db->execute('UPDATE projects SET deleted_at = ? WHERE workspace_id = ? AND deleted_at IS NULL', [$now, $workspaceId]);
            $db->execute('UPDATE workspaces SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL', [$now, $now, $workspaceId]);
        });
    }
}
