<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Alerts and the weekly digest: each detected event is stored once per
 * workspace, type and fingerprint (for example "a ranking drop on the check
 * of 2026-10-04"), e-mailed to the members who can see it, and kept for the
 * digest and the history. Users choose what they receive in
 * notification_settings; digest_log makes the weekly digest go out once.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS alerts (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                project_id   BIGINT UNSIGNED NULL,
                type         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                fingerprint  VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                title        VARCHAR(255)    NOT NULL,
                `lines`      TEXT            NOT NULL,
                url          VARCHAR(255)    NOT NULL,
                created_at   DATETIME        NOT NULL,
                sent_at      DATETIME        NULL,
                PRIMARY KEY (id),
                UNIQUE KEY alerts_public_id_unique (public_id),
                UNIQUE KEY alerts_fingerprint_unique (workspace_id, type, fingerprint),
                KEY alerts_project_created (project_id, created_at),
                KEY alerts_workspace_created (workspace_id, created_at),
                CONSTRAINT alerts_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT alerts_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS notification_settings (
                user_id        BIGINT UNSIGNED NOT NULL,
                alerts         TINYINT(1)      NOT NULL DEFAULT 1,
                muted_types    JSON            NOT NULL,
                muted_projects JSON            NOT NULL,
                digest         TINYINT(1)      NOT NULL DEFAULT 1,
                digest_ai      TINYINT(1)      NOT NULL DEFAULT 0,
                updated_at     DATETIME        NOT NULL,
                PRIMARY KEY (user_id),
                CONSTRAINT notification_settings_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS digest_log (
                user_id      BIGINT UNSIGNED NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                week         CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                created_at   DATETIME        NOT NULL,
                PRIMARY KEY (user_id, workspace_id, week),
                CONSTRAINT digest_log_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT digest_log_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
