<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Article ideas (ADR-041, G1). A run buys competitors' ranked keywords
 * once (bounded rows per competitor); the rows are kept for the newest two
 * runs so ideas can be rebuilt for free when topics, relevance or your own
 * rankings change. Ideas are rebuilt as a whole; dismissals are kept apart
 * so a rebuild never brings a dismissed idea back.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS article_idea_runs (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id   BIGINT UNSIGNED NOT NULL,
            project_id     BIGINT UNSIGNED NOT NULL,
            status         VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            market         VARCHAR(64) NULL,
            competitors    TEXT NOT NULL,
            estimated_cost DOUBLE NOT NULL DEFAULT 0,
            requested_by   BIGINT UNSIGNED NULL,
            error          VARCHAR(500) NULL,
            created_at     DATETIME NOT NULL,
            finished_at    DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY article_idea_runs_public (public_id),
            KEY article_idea_runs_project (project_id, id),
            CONSTRAINT article_idea_runs_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT article_idea_runs_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS article_competitor_rows (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id     BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            domain     VARCHAR(255) NOT NULL,
            found      TINYINT(1) NOT NULL DEFAULT 0,
            url        VARCHAR(2048) NOT NULL,
            keyword    VARCHAR(255) NOT NULL,
            volume     INT UNSIGNED NULL,
            difficulty TINYINT UNSIGNED NULL,
            position   SMALLINT UNSIGNED NULL,
            traffic    DOUBLE NULL,
            avg_rd     INT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY article_competitor_rows_run (run_id),
            CONSTRAINT article_competitor_rows_run_fk FOREIGN KEY (run_id) REFERENCES article_idea_runs (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS article_ideas (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id   BIGINT UNSIGNED NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            run_id       BIGINT UNSIGNED NOT NULL,
            idea_key     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            kind         VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            main_keyword VARCHAR(255) NOT NULL,
            score        DOUBLE NOT NULL,
            payload      MEDIUMTEXT NOT NULL,
            built_at     DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY article_ideas_key (project_id, idea_key),
            KEY article_ideas_rank (project_id, score),
            CONSTRAINT article_ideas_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT article_ideas_run_fk FOREIGN KEY (run_id) REFERENCES article_idea_runs (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS article_idea_dismissals (
            project_id   BIGINT UNSIGNED NOT NULL,
            idea_key     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            main_keyword VARCHAR(255) NOT NULL,
            dismissed_by BIGINT UNSIGNED NULL,
            dismissed_at DATETIME NOT NULL,
            PRIMARY KEY (project_id, idea_key),
            CONSTRAINT article_idea_dismissals_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
