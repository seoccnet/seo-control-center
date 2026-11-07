<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Keywords (0.3, part 2): keywords tracked per project and locale, the
 * latest keyword metrics per workspace, and the metrics update status.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS project_keywords (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                project_id    BIGINT UNSIGNED NOT NULL,
                keyword       VARCHAR(100)    NOT NULL,
                keyword_hash  BINARY(32)      NOT NULL,
                location_code INT UNSIGNED    NOT NULL,
                language_code VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                created_by    BIGINT UNSIGNED NULL,
                created_at    DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY project_keywords_public_id_unique (public_id),
                UNIQUE KEY project_keywords_unique (project_id, keyword_hash, location_code, language_code),
                KEY project_keywords_project_created (project_id, created_at),
                CONSTRAINT project_keywords_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT project_keywords_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        // Latest metrics per workspace, keyword and locale. Monthly volumes
        // (up to 24 months, JSON) carry the history the provider reports.
        $db->statement(
            'CREATE TABLE IF NOT EXISTS keyword_metrics (
                workspace_id      BIGINT UNSIGNED NOT NULL,
                keyword_hash      BINARY(32)      NOT NULL,
                location_code     INT UNSIGNED    NOT NULL,
                language_code     VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                keyword           VARCHAR(100)    NOT NULL,
                search_volume     INT UNSIGNED    NULL,
                cpc               DECIMAL(10,2)   NULL,
                competition       TINYINT UNSIGNED NULL,
                competition_level VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
                monthly_searches  TEXT            NULL,
                fetched_at        DATETIME        NOT NULL,
                PRIMARY KEY (workspace_id, keyword_hash, location_code, language_code),
                KEY keyword_metrics_fetched (workspace_id, fetched_at),
                CONSTRAINT keyword_metrics_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS project_keyword_sync (
                project_id      BIGINT UNSIGNED NOT NULL,
                status          ENUM('pending', 'running', 'ok', 'error') NOT NULL,
                last_error      VARCHAR(500)    NULL,
                requested_at    DATETIME        NULL,
                last_success_at DATETIME        NULL,
                updated_at      DATETIME        NOT NULL,
                PRIMARY KEY (project_id),
                CONSTRAINT project_keyword_sync_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
