<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Bing Webmaster Tools (1.1): one API key per workspace, a Bing site per
 * project, and the imported data.
 *
 * Bing's API returns daily totals (clicks and impressions, no position) and
 * top queries and pages in weekly buckets (each row covers the 7 days ending
 * on its date). Every import receives Bing's full history, so a project's
 * rows are replaced as a whole.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        // The API key is encrypted; `sites` caches the verified sites of the key's account.
        $db->statement(
            "CREATE TABLE IF NOT EXISTS bing_profiles (
                workspace_id BIGINT UNSIGNED NOT NULL,
                api_key      VARBINARY(512)  NOT NULL,
                sites        JSON            NOT NULL,
                status       ENUM('active', 'error') NOT NULL DEFAULT 'active',
                last_error   VARCHAR(500)    NULL,
                checked_at   DATETIME        NULL,
                created_by   BIGINT UNSIGNED NULL,
                created_at   DATETIME        NOT NULL,
                updated_at   DATETIME        NOT NULL,
                PRIMARY KEY (workspace_id),
                CONSTRAINT bing_profiles_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT bing_profiles_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        // The Bing site linked to a project, with its import state.
        $db->statement(
            "CREATE TABLE IF NOT EXISTS bing_project_sites (
                project_id      BIGINT UNSIGNED NOT NULL,
                workspace_id    BIGINT UNSIGNED NOT NULL,
                site_url        VARCHAR(2048)   NOT NULL,
                status          ENUM('pending', 'importing', 'ok', 'error') NOT NULL DEFAULT 'pending',
                last_success_at DATETIME        NULL,
                last_error      VARCHAR(500)    NULL,
                linked_by       BIGINT UNSIGNED NULL,
                linked_at       DATETIME        NOT NULL,
                PRIMARY KEY (project_id),
                KEY bing_project_sites_workspace (workspace_id),
                CONSTRAINT bing_project_sites_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT bing_project_sites_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT bing_project_sites_linked_by_fk FOREIGN KEY (linked_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        // Daily totals. Dates are Bing's (Pacific Time).
        $db->statement(
            'CREATE TABLE IF NOT EXISTS bing_daily (
                project_id  BIGINT UNSIGNED NOT NULL,
                date        DATE            NOT NULL,
                clicks      INT UNSIGNED    NOT NULL,
                impressions INT UNSIGNED    NOT NULL,
                PRIMARY KEY (project_id, date),
                CONSTRAINT bing_daily_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB',
        );

        // Weekly query and page rows; week_end is the last day of the 7-day bucket.
        $db->statement(
            "CREATE TABLE IF NOT EXISTS bing_weekly (
                project_id  BIGINT UNSIGNED NOT NULL,
                dimension   ENUM('query', 'page') NOT NULL,
                week_end    DATE            NOT NULL,
                key_hash    BINARY(32)      NOT NULL,
                key_text    VARCHAR(2048)   NOT NULL,
                clicks      INT UNSIGNED    NOT NULL,
                impressions INT UNSIGNED    NOT NULL,
                position    DECIMAL(8,2)    NOT NULL,
                PRIMARY KEY (project_id, dimension, week_end, key_hash),
                CONSTRAINT bing_weekly_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
