<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Fix plans written by the AI assistant (ADR-028): a per-page list of
 * changes (URL, issue, current value, suggested value, note) that the user
 * downloads as CSV for whoever edits the website. Private to the user who
 * asked for it.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS assistant_fix_plans (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                project_id   BIGINT UNSIGNED NOT NULL,
                user_id      BIGINT UNSIGNED NOT NULL,
                title        VARCHAR(200)    NOT NULL,
                `rows`       MEDIUMTEXT      NOT NULL,
                created_at   DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY assistant_fix_plans_public_id_unique (public_id),
                KEY assistant_fix_plans_user (workspace_id, user_id, created_at),
                CONSTRAINT assistant_fix_plans_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT assistant_fix_plans_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT assistant_fix_plans_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
