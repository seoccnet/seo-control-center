<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS projects (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                workspace_id   BIGINT UNSIGNED NOT NULL,
                name           VARCHAR(120)    NOT NULL,
                primary_domain VARCHAR(253) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
                timezone       VARCHAR(64)     NOT NULL DEFAULT \'UTC\',
                created_by     BIGINT UNSIGNED NULL,
                created_at     DATETIME        NOT NULL,
                updated_at     DATETIME        NOT NULL,
                deleted_at     DATETIME        NULL,
                PRIMARY KEY (id),
                UNIQUE KEY projects_public_id_unique (public_id),
                KEY projects_workspace_active (workspace_id, deleted_at, name),
                CONSTRAINT projects_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id),
                CONSTRAINT projects_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS project_members (
                project_id BIGINT UNSIGNED NOT NULL,
                user_id    BIGINT UNSIGNED NOT NULL,
                role_id    INT UNSIGNED    NULL,
                created_at DATETIME        NOT NULL,
                PRIMARY KEY (project_id, user_id),
                KEY project_members_user (user_id),
                CONSTRAINT project_members_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT project_members_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT project_members_role_fk FOREIGN KEY (role_id) REFERENCES roles (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
