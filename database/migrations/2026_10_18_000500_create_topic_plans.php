<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Topic plans (ADR-041, G2): one per pillar article idea, with the members
 * you keep (supporting ideas, searches, your pages), the internal link map
 * and, when bought, the backlinks of the pillar's top competitor pages.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS topic_plans (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id      BIGINT UNSIGNED NOT NULL,
            project_id        BIGINT UNSIGNED NOT NULL,
            pillar_key        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            pillar_keyword    VARCHAR(255) NOT NULL,
            members           MEDIUMTEXT NOT NULL,
            links             MEDIUMTEXT NULL,
            links_status      VARCHAR(16) NULL,
            links_built_at    DATETIME NULL,
            backlinks         TEXT NULL,
            backlinks_status  VARCHAR(16) NULL,
            backlinks_error   VARCHAR(500) NULL,
            backlinks_at      DATETIME NULL,
            backlinks_cost    DECIMAL(10,4) NOT NULL DEFAULT 0,
            created_by        BIGINT UNSIGNED NULL,
            created_at        DATETIME NOT NULL,
            updated_at        DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY topic_plans_public (public_id),
            UNIQUE KEY topic_plans_pillar (project_id, pillar_key),
            CONSTRAINT topic_plans_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT topic_plans_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
