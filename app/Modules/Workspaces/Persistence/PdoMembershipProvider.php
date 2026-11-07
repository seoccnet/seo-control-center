<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Workspaces\Persistence;

use SeoControlCenter\Core\Authorization\Membership;
use SeoControlCenter\Core\Authorization\MembershipProvider;
use SeoControlCenter\Core\Database\Connection;

final readonly class PdoMembershipProvider implements MembershipProvider
{
    public function __construct(private Connection $db) {}

    public function workspaceMembership(int $userId, int $workspaceId): ?Membership
    {
        $row = $this->db->fetchOne(
            "SELECT r.id AS role_id, r.role_key
               FROM workspace_users wu
               JOIN roles r ON r.id = wu.role_id
               JOIN users u ON u.id = wu.user_id
               JOIN workspaces w ON w.id = wu.workspace_id
              WHERE wu.user_id = ? AND wu.workspace_id = ? AND wu.status = 'active' AND u.status = 'active' AND w.deleted_at IS NULL",
            [$userId, $workspaceId],
        );
        if ($row === null) {
            return null;
        }

        return new Membership((string) $row['role_key'], $this->permissionsForRole((int) $row['role_id']));
    }

    public function projectMembership(int $userId, int $projectId): ?Membership
    {
        $row = $this->db->fetchOne(
            'SELECT pm.role_id, r.role_key
               FROM project_members pm
               LEFT JOIN roles r ON r.id = pm.role_id
              WHERE pm.user_id = ? AND pm.project_id = ?',
            [$userId, $projectId],
        );
        if ($row === null) {
            return null;
        }
        if ($row['role_id'] === null) {
            return new Membership('', []);
        }

        return new Membership((string) $row['role_key'], $this->permissionsForRole((int) $row['role_id']));
    }

    /**
     * @return list<string>
     */
    private function permissionsForRole(int $roleId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
            [$roleId],
        );

        return array_map(static fn(array $row): string => (string) $row['name'], $rows);
    }
}
