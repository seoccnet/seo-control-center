<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * IndexNow (1.1): tells Bing and the other IndexNow engines (Yandex, Seznam,
 * Naver, …) about new and changed URLs. Per project: the key (public by
 * design, it is served as a file on the site), the sitemap to watch, the URLs
 * seen in it and a log of submissions.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS indexnow_settings (
                project_id      BIGINT UNSIGNED NOT NULL,
                workspace_id    BIGINT UNSIGNED NOT NULL,
                api_key         CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                host            VARCHAR(253)    NULL,
                key_verified_at DATETIME        NULL,
                sitemap_url     VARCHAR(2048)   NULL,
                auto_submit     TINYINT(1)      NOT NULL DEFAULT 0,
                sitemap_checked_at DATETIME     NULL,
                last_error      VARCHAR(500)    NULL,
                created_at      DATETIME        NOT NULL,
                updated_at      DATETIME        NOT NULL,
                PRIMARY KEY (project_id),
                KEY indexnow_settings_auto (auto_submit),
                CONSTRAINT indexnow_settings_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT indexnow_settings_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        // URLs found in the sitemap; lastmod as written there.
        $db->statement(
            'CREATE TABLE IF NOT EXISTS indexnow_urls (
                project_id    BIGINT UNSIGNED NOT NULL,
                url_hash      BINARY(32)      NOT NULL,
                url           VARCHAR(2048)   NOT NULL,
                lastmod       VARCHAR(40)     NULL,
                first_seen_at DATETIME        NOT NULL,
                submitted_at  DATETIME        NULL,
                PRIMARY KEY (project_id, url_hash),
                CONSTRAINT indexnow_urls_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS indexnow_submissions (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id  BIGINT UNSIGNED NOT NULL,
                source      ENUM('manual', 'sitemap') NOT NULL,
                url_count   INT UNSIGNED    NOT NULL,
                status_code SMALLINT UNSIGNED NULL,
                result      VARCHAR(500)    NOT NULL,
                created_by  BIGINT UNSIGNED NULL,
                created_at  DATETIME        NOT NULL,
                PRIMARY KEY (id),
                KEY indexnow_submissions_project (project_id, created_at),
                CONSTRAINT indexnow_submissions_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT indexnow_submissions_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
