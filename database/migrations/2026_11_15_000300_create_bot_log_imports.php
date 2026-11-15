<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Search bot log analysis (1.2.0): an uploaded access log is read in the
 * background and kept only as totals per bot, path and status code; the
 * file itself is deleted once read. byte_offset lets a stopped import
 * resume; totals and offset are saved in one transaction, so a retry never
 * counts a request twice.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS bot_log_imports (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id  BIGINT UNSIGNED NOT NULL,
            project_id    BIGINT UNSIGNED NOT NULL,
            file_name     VARCHAR(255) NOT NULL,
            file_bytes    BIGINT UNSIGNED NOT NULL,
            status        VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            byte_offset   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            lines_read    INT UNSIGNED NOT NULL DEFAULT 0,
            bot_lines     INT UNSIGNED NOT NULL DEFAULT 0,
            dropped_hits  INT UNSIGNED NOT NULL DEFAULT 0,
            first_seen    DATETIME NULL,
            last_seen     DATETIME NULL,
            error         VARCHAR(500) NULL,
            created_by    BIGINT UNSIGNED NULL,
            created_at    DATETIME NOT NULL,
            updated_at    DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY bot_log_imports_public (public_id),
            KEY bot_log_imports_project (project_id, id),
            KEY bot_log_imports_status (status, updated_at),
            CONSTRAINT bot_log_imports_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT bot_log_imports_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT bot_log_imports_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS bot_log_hits (
            import_id   BIGINT UNSIGNED NOT NULL,
            bot         VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            path_hash   BINARY(20) NOT NULL,
            status      SMALLINT UNSIGNED NOT NULL,
            path        VARCHAR(1024) NOT NULL,
            hits        INT UNSIGNED NOT NULL,
            PRIMARY KEY (import_id, bot, path_hash, status),
            KEY bot_log_hits_bot (import_id, bot, hits),
            CONSTRAINT bot_log_hits_import_fk FOREIGN KEY (import_id) REFERENCES bot_log_imports (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
