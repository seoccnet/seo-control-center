<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Workspaces, roles and permissions (ADR-013, SECURITY.md -> Authorization).
 * Seeds the system roles and the 0.1 permission catalog.
 */
return new class implements Migration {
    /** @var array<string, string> */
    private const array PERMISSIONS = [
        'workspace.view' => 'View the workspace',
        'workspace.manage' => 'Change workspace name and settings',
        'workspace.members.manage' => 'Invite, remove and change members',
        'workspace.delete' => 'Delete the workspace',
        'workspace.transfer' => 'Transfer workspace ownership',
        'projects.view' => 'View projects',
        'projects.create' => 'Create projects',
        'projects.edit' => 'Edit projects',
        'projects.delete' => 'Delete projects',
        'integrations.manage' => 'Connect and disconnect integrations',
        'analysis.execute' => 'Run analyses that spend provider budget',
        'audit.view' => 'View the audit log',
        'tokens.manage' => 'Create and revoke API/MCP tokens',
    ];

    /** @var array<string, array{label: string, permissions: list<string>}> */
    private const array ROLES = [
        'owner' => ['label' => 'Owner', 'permissions' => [
            'workspace.view', 'workspace.manage', 'workspace.members.manage', 'workspace.delete', 'workspace.transfer',
            'projects.view', 'projects.create', 'projects.edit', 'projects.delete',
            'integrations.manage', 'analysis.execute', 'audit.view', 'tokens.manage',
        ]],
        'admin' => ['label' => 'Admin', 'permissions' => [
            'workspace.view', 'workspace.manage', 'workspace.members.manage',
            'projects.view', 'projects.create', 'projects.edit', 'projects.delete',
            'integrations.manage', 'analysis.execute', 'audit.view', 'tokens.manage',
        ]],
        'member' => ['label' => 'Member', 'permissions' => [
            'workspace.view', 'projects.view', 'projects.edit', 'analysis.execute',
        ]],
        'viewer' => ['label' => 'Viewer', 'permissions' => [
            'workspace.view', 'projects.view',
        ]],
    ];

    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS workspaces (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                name       VARCHAR(120) NOT NULL,
                created_at DATETIME     NOT NULL,
                updated_at DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY workspaces_public_id_unique (public_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS permissions (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                description VARCHAR(255) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY permissions_name_unique (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS roles (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                workspace_id BIGINT UNSIGNED NULL,
                role_key     VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                label        VARCHAR(100) NOT NULL,
                is_system    TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY roles_workspace_key_unique (workspace_id, role_key),
                CONSTRAINT roles_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS role_permissions (
                role_id       INT UNSIGNED NOT NULL,
                permission_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (role_id, permission_id),
                CONSTRAINT role_permissions_role_fk FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
                CONSTRAINT role_permissions_permission_fk FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS workspace_users (
                workspace_id BIGINT UNSIGNED NOT NULL,
                user_id      BIGINT UNSIGNED NOT NULL,
                role_id      INT UNSIGNED    NOT NULL,
                status       ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
                created_at   DATETIME NOT NULL,
                PRIMARY KEY (workspace_id, user_id),
                KEY workspace_users_user (user_id),
                CONSTRAINT workspace_users_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT workspace_users_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT workspace_users_role_fk FOREIGN KEY (role_id) REFERENCES roles (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        foreach (self::PERMISSIONS as $name => $description) {
            $db->execute('INSERT INTO permissions (name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)', [$name, $description]);
        }
        foreach (self::ROLES as $key => $role) {
            // NULL workspace IDs do not conflict with the unique key in MySQL.
            $roleId = $db->fetchValue('SELECT id FROM roles WHERE workspace_id IS NULL AND role_key = ? AND is_system = 1 ORDER BY id LIMIT 1', [$key]);
            if ($roleId === null) {
                $db->execute('INSERT INTO roles (workspace_id, role_key, label, is_system) VALUES (NULL, ?, ?, 1)', [$key, $role['label']]);
                $roleId = $db->lastInsertId();
            }
            foreach ($role['permissions'] as $permission) {
                $db->execute(
                    'INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = ? ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)',
                    [$roleId, $permission],
                );
            }
        }
    }
};
