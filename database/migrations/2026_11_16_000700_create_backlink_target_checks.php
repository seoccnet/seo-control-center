<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Link reclamation (1.5.0): the pages of the project that other sites link
 * to (from the stored backlink list) are checked with one request each,
 * and the answer is kept, so links pointing to missing pages can be listed
 * with a suggested redirect. `backlink_target_runs` holds the state of the
 * latest check per project.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS backlink_target_checks (
                project_id  BIGINT UNSIGNED   NOT NULL,
                url_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                url         VARCHAR(2048)     NOT NULL,
                status      SMALLINT UNSIGNED NULL,
                location    VARCHAR(2048)     NULL,
                final_status SMALLINT UNSIGNED NULL,
                error       VARCHAR(255)      NULL,
                checked_at  DATETIME          NOT NULL,
                PRIMARY KEY (project_id, url_hash),
                CONSTRAINT backlink_target_checks_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS backlink_target_runs (
                project_id   BIGINT UNSIGNED NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                state        VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                error        VARCHAR(255)    NULL,
                queued_at    DATETIME        NOT NULL,
                finished_at  DATETIME        NULL,
                PRIMARY KEY (project_id),
                CONSTRAINT backlink_target_runs_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT backlink_target_runs_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
