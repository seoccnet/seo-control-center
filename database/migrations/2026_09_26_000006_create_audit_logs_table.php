<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Audit log: who did what, when, to what (SECURITY.md -> Audit log).
 * Append-only; no foreign keys so records survive deletion of the actor/target.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                workspace_id   BIGINT UNSIGNED NULL,
                actor_type     ENUM('user', 'system', 'cli', 'mcp', 'api') NOT NULL,
                actor_user_id  BIGINT UNSIGNED NULL,
                actor_label    VARCHAR(191)    NULL,
                action         VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                target_type    VARCHAR(50)  CHARACTER SET ascii COLLATE ascii_bin NULL,
                target_id      VARCHAR(64)  CHARACTER SET ascii COLLATE ascii_bin NULL,
                outcome        ENUM('success', 'failure') NOT NULL DEFAULT 'success',
                ip_address     VARCHAR(45)     NULL,
                user_agent     VARCHAR(255)    NULL,
                request_id     CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
                metadata       JSON            NULL,
                created_at     DATETIME        NOT NULL,
                PRIMARY KEY (id),
                KEY audit_logs_workspace_created (workspace_id, created_at),
                KEY audit_logs_actor_created (actor_user_id, created_at),
                KEY audit_logs_action_created (action, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
