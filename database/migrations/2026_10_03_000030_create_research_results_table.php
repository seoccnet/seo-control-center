<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Keyword research (1.3): the latest result of each paid lookup per
 * project, so reading it again costs nothing. `kind` is ranked (the
 * project's ranking keywords), gap (per competitor in `subject`), ideas,
 * competitors or overview. `items` holds the normalised rows as JSON;
 * `params` what was asked (seed keywords, locale).
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS research_results (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id   BIGINT UNSIGNED NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                kind         ENUM('ranked', 'gap', 'ideas', 'competitors', 'overview') NOT NULL,
                subject      VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
                status       ENUM('queued', 'done', 'failed') NOT NULL,
                params       JSON            NOT NULL,
                items        MEDIUMTEXT      NULL,
                item_count   INT UNSIGNED    NOT NULL DEFAULT 0,
                error        VARCHAR(500)    NULL,
                requested_by BIGINT UNSIGNED NULL,
                requested_at DATETIME        NOT NULL,
                fetched_at   DATETIME        NULL,
                PRIMARY KEY (id),
                UNIQUE KEY research_results_subject (project_id, kind, subject),
                CONSTRAINT research_results_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT research_results_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT research_results_requested_by_fk FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
