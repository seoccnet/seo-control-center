<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Backlinks (1.3): lookups are stored like the research lookups (new kinds
 * in research_results); the link profiles of the project and its
 * competitors are also kept per check day for the trend, and a project can
 * have them checked every month.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "ALTER TABLE research_results
                MODIFY kind ENUM('ranked', 'gap', 'ideas', 'competitors', 'overview', 'llm', 'llm_prompts',
                                 'bl_profiles', 'bl_domains', 'bl_new', 'bl_lost', 'bl_anchors', 'bl_gap') NOT NULL",
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS backlink_settings (
                project_id      BIGINT UNSIGNED NOT NULL,
                workspace_id    BIGINT UNSIGNED NOT NULL,
                monthly         TINYINT(1)      NOT NULL DEFAULT 0,
                last_checked_on DATE            NULL,
                updated_at      DATETIME        NOT NULL,
                PRIMARY KEY (project_id),
                KEY backlink_settings_monthly (monthly, last_checked_on),
                CONSTRAINT backlink_settings_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT backlink_settings_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS backlink_history (
                project_id        BIGINT UNSIGNED  NOT NULL,
                checked_on        DATE             NOT NULL,
                domain            VARCHAR(255)     NOT NULL,
                domain_rank       SMALLINT UNSIGNED NULL,
                backlinks         BIGINT UNSIGNED  NULL,
                referring_domains INT UNSIGNED     NULL,
                spam_score        TINYINT UNSIGNED NULL,
                PRIMARY KEY (project_id, checked_on, domain),
                CONSTRAINT backlink_history_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
