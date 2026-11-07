<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Investigations (ADR-038, D3): saved research notebooks per project with a
 * question, a status and a conclusion, and their dated items (sources,
 * checks, limitations and notes). People and MCP clients add to them.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS investigations (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            project_id   BIGINT UNSIGNED NOT NULL,
            title        VARCHAR(200) NOT NULL,
            question     TEXT NULL,
            status       VARCHAR(12) NOT NULL DEFAULT \'open\',
            conclusion   TEXT NULL,
            created_by   BIGINT UNSIGNED NULL,
            via          VARCHAR(120) NULL,
            revision     INT UNSIGNED NOT NULL DEFAULT 1,
            created_at   DATETIME NOT NULL,
            updated_at   DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY investigations_public (public_id),
            KEY investigations_project (workspace_id, project_id, status, updated_at),
            CONSTRAINT investigations_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT investigations_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT investigations_user_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS investigation_items (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            investigation_id BIGINT UNSIGNED NOT NULL,
            kind             VARCHAR(12) NOT NULL,
            ref_type         VARCHAR(20) NULL,
            ref_id           VARCHAR(2048) NULL,
            label            VARCHAR(255) NOT NULL,
            detail           TEXT NULL,
            observed_on      DATE NULL,
            added_by         BIGINT UNSIGNED NULL,
            via              VARCHAR(120) NULL,
            created_at       DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY investigation_items_public (public_id),
            KEY investigation_items_investigation (investigation_id, id),
            CONSTRAINT investigation_items_investigation_fk FOREIGN KEY (investigation_id) REFERENCES investigations (id) ON DELETE CASCADE,
            CONSTRAINT investigation_items_user_fk FOREIGN KEY (added_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
