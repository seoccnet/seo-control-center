<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Scheduled reports (1.1): one schedule per project (weekly or monthly, up to
 * ten recipients) and a log of every delivery. `last_period` holds the key of
 * the last period sent ("2026-09-21..2026-09-27"), so a period goes out once.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS report_schedules (
                project_id   BIGINT UNSIGNED NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                frequency    ENUM('weekly', 'monthly') NOT NULL,
                recipients   JSON            NOT NULL,
                enabled      TINYINT(1)      NOT NULL DEFAULT 1,
                last_period  VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
                last_sent_at DATETIME        NULL,
                updated_by   BIGINT UNSIGNED NULL,
                created_at   DATETIME        NOT NULL,
                updated_at   DATETIME        NOT NULL,
                PRIMARY KEY (project_id),
                KEY report_schedules_enabled (enabled),
                CONSTRAINT report_schedules_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT report_schedules_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT report_schedules_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS report_deliveries (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id  BIGINT UNSIGNED NOT NULL,
                period      VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                kind        ENUM('scheduled', 'test') NOT NULL,
                recipients  SMALLINT UNSIGNED NOT NULL,
                failed      SMALLINT UNSIGNED NOT NULL,
                error       VARCHAR(500)    NULL,
                created_at  DATETIME        NOT NULL,
                PRIMARY KEY (id),
                KEY report_deliveries_project (project_id, created_at),
                CONSTRAINT report_deliveries_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
