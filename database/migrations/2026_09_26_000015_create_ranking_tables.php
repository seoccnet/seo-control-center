<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Rank tracking (0.4, part 1): per-project settings, one snapshot per
 * keyword, device and day, daily aggregates and the check status.
 * Check days are UTC dates.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS project_ranking_settings (
                project_id BIGINT UNSIGNED NOT NULL,
                enabled    TINYINT(1)      NOT NULL DEFAULT 0,
                device     ENUM('desktop', 'mobile') NOT NULL DEFAULT 'desktop',
                frequency  ENUM('daily', 'weekly')   NOT NULL DEFAULT 'daily',
                updated_by BIGINT UNSIGNED NULL,
                updated_at DATETIME        NOT NULL,
                PRIMARY KEY (project_id),
                KEY project_ranking_settings_enabled (enabled),
                CONSTRAINT project_ranking_settings_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT project_ranking_settings_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        // Historical facts (ADR-005): never overwritten except by a re-check of the same day.
        $db->statement(
            "CREATE TABLE IF NOT EXISTS ranking_snapshots (
                id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_keyword_id BIGINT UNSIGNED NOT NULL,
                project_id         BIGINT UNSIGNED NOT NULL,
                device             ENUM('desktop', 'mobile') NOT NULL,
                checked_on         DATE            NOT NULL,
                position           TINYINT UNSIGNED NULL,
                absolute_position  SMALLINT UNSIGNED NULL,
                url                VARCHAR(2048)   NULL,
                serp_features      VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NULL,
                top_results        TEXT            NULL,
                checked_at         DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ranking_snapshots_keyword_day (project_keyword_id, device, checked_on),
                KEY ranking_snapshots_project_day (project_id, device, checked_on),
                CONSTRAINT ranking_snapshots_keyword_fk FOREIGN KEY (project_keyword_id) REFERENCES project_keywords (id) ON DELETE CASCADE,
                CONSTRAINT ranking_snapshots_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS ranking_daily (
                project_id       BIGINT UNSIGNED NOT NULL,
                device           ENUM('desktop', 'mobile') NOT NULL,
                date             DATE            NOT NULL,
                keywords         INT UNSIGNED    NOT NULL,
                ranked           INT UNSIGNED    NOT NULL,
                top3             INT UNSIGNED    NOT NULL,
                top10            INT UNSIGNED    NOT NULL,
                average_position DECIMAL(6,2)    NULL,
                visibility       DECIMAL(8,4)    NOT NULL,
                PRIMARY KEY (project_id, device, date),
                CONSTRAINT ranking_daily_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS project_ranking_sync (
                project_id      BIGINT UNSIGNED NOT NULL,
                status          ENUM('pending', 'running', 'ok', 'error') NOT NULL,
                last_error      VARCHAR(500)    NULL,
                last_checked_on DATE            NULL,
                last_success_at DATETIME        NULL,
                updated_at      DATETIME        NOT NULL,
                PRIMARY KEY (project_id),
                CONSTRAINT project_ranking_sync_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
