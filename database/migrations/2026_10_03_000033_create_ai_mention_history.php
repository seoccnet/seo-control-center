<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * AI assistant mentions over time (1.3): whether a project checks them
 * every week, and one row per check day, domain and platform with the
 * mentions and AI search volume, so the trend survives later checks.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS ai_mention_settings (
                project_id      BIGINT UNSIGNED NOT NULL,
                workspace_id    BIGINT UNSIGNED NOT NULL,
                weekly          TINYINT(1)      NOT NULL DEFAULT 0,
                last_checked_on DATE            NULL,
                updated_at      DATETIME        NOT NULL,
                PRIMARY KEY (project_id),
                KEY ai_mention_settings_weekly (weekly, last_checked_on),
                CONSTRAINT ai_mention_settings_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT ai_mention_settings_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS ai_mention_history (
                project_id       BIGINT UNSIGNED NOT NULL,
                checked_on       DATE            NOT NULL,
                platform         VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                domain           VARCHAR(255)    NOT NULL,
                mentions         INT UNSIGNED    NOT NULL,
                ai_search_volume BIGINT UNSIGNED NULL,
                PRIMARY KEY (project_id, checked_on, platform, domain),
                CONSTRAINT ai_mention_history_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
