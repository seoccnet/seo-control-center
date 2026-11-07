<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * "Why they outrank you" analyses (ADR-038, D1): one row per run for a saved
 * topic. Structure and verdict are JSON; no competitor text is stored.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS competitor_analyses (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id   BIGINT UNSIGNED NOT NULL,
            project_id     BIGINT UNSIGNED NOT NULL,
            cluster_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            label          VARCHAR(255) NOT NULL,
            keyword        VARCHAR(255) NOT NULL,
            keywords       JSON NOT NULL,
            demand         DOUBLE NOT NULL DEFAULT 0,
            status         VARCHAR(12) NOT NULL,
            serp_source    VARCHAR(12) NOT NULL,
            location_code  INT UNSIGNED NOT NULL,
            language_code  VARCHAR(8) NOT NULL,
            results        JSON NULL,
            own_url        TEXT NULL,
            own_position   INT UNSIGNED NULL,
            pages          JSON NULL,
            verdict        JSON NULL,
            cost           DECIMAL(10,4) NULL,
            error          VARCHAR(500) NULL,
            requested_by   BIGINT UNSIGNED NULL,
            requested_at   DATETIME NOT NULL,
            started_at     DATETIME NULL,
            finished_at    DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY competitor_analyses_public (public_id),
            KEY competitor_analyses_cluster (workspace_id, project_id, cluster_id, requested_at),
            KEY competitor_analyses_done (project_id, status, finished_at),
            CONSTRAINT competitor_analyses_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT competitor_analyses_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT competitor_analyses_user_fk FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
