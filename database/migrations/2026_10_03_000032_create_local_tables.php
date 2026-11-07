<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Local SEO (1.3): per project the business, the map area and the local
 * keywords; per check day the Google Maps position of each keyword (with
 * the top results) and a Business Profile snapshot (rating trend).
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS local_settings (
                project_id      BIGINT UNSIGNED  NOT NULL,
                workspace_id    BIGINT UNSIGNED  NOT NULL,
                business_name   VARCHAR(200)     NOT NULL,
                latitude        DECIMAL(9,6)     NOT NULL,
                longitude       DECIMAL(9,6)     NOT NULL,
                zoom            TINYINT UNSIGNED NOT NULL,
                language        VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                weekly          TINYINT(1)       NOT NULL DEFAULT 0,
                cid             VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
                status          ENUM('idle', 'queued', 'failed') NOT NULL DEFAULT 'idle',
                last_error      VARCHAR(500)     NULL,
                last_checked_on DATE             NULL,
                updated_by      BIGINT UNSIGNED  NULL,
                created_at      DATETIME         NOT NULL,
                updated_at      DATETIME         NOT NULL,
                PRIMARY KEY (project_id),
                KEY local_settings_weekly (weekly, last_checked_on),
                CONSTRAINT local_settings_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT local_settings_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT local_settings_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS local_keywords (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                keyword    VARCHAR(100)    NOT NULL,
                created_at DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY local_keywords_unique (project_id, keyword),
                CONSTRAINT local_keywords_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS local_rankings (
                local_keyword_id BIGINT UNSIGNED   NOT NULL,
                project_id       BIGINT UNSIGNED   NOT NULL,
                checked_on       DATE              NOT NULL,
                position         SMALLINT UNSIGNED NULL,
                top              JSON              NOT NULL,
                PRIMARY KEY (local_keyword_id, checked_on),
                KEY local_rankings_project_day (project_id, checked_on),
                CONSTRAINT local_rankings_keyword_fk FOREIGN KEY (local_keyword_id) REFERENCES local_keywords (id) ON DELETE CASCADE,
                CONSTRAINT local_rankings_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS local_profiles (
                project_id BIGINT UNSIGNED NOT NULL,
                checked_on DATE            NOT NULL,
                title      VARCHAR(300)    NOT NULL,
                category   VARCHAR(150)    NULL,
                rating     DECIMAL(2,1)    NULL,
                votes      INT UNSIGNED    NULL,
                claimed    TINYINT(1)      NULL,
                address    VARCHAR(300)    NULL,
                phone      VARCHAR(50)     NULL,
                url        VARCHAR(2048)   NULL,
                photos     INT UNSIGNED    NULL,
                PRIMARY KEY (project_id, checked_on),
                CONSTRAINT local_profiles_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
