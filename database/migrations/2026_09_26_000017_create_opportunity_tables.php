<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Opportunities (0.6, part 1): rule-based opportunities per project with
 * their evidence and scores, the status history and the last analysis run.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS opportunities (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                project_id        BIGINT UNSIGNED NOT NULL,
                type              VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                subject           VARCHAR(2100)   NOT NULL,
                subject_hash      BINARY(32)      NOT NULL,
                title             VARCHAR(500)    NOT NULL,
                explanation       TEXT            NOT NULL,
                evidence          TEXT            NOT NULL,
                impact            TINYINT UNSIGNED NOT NULL,
                confidence        TINYINT UNSIGNED NOT NULL,
                effort            TINYINT UNSIGNED NOT NULL,
                priority          DECIMAL(6,2)    NOT NULL,
                status            ENUM('open', 'done', 'dismissed', 'resolved') NOT NULL,
                first_detected_at DATETIME        NOT NULL,
                last_detected_at  DATETIME        NOT NULL,
                status_changed_at DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY opportunities_public_id_unique (public_id),
                UNIQUE KEY opportunities_subject (project_id, type, subject_hash),
                KEY opportunities_list (project_id, status, priority),
                CONSTRAINT opportunities_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS opportunity_status_history (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                opportunity_id BIGINT UNSIGNED NOT NULL,
                from_status    ENUM('open', 'done', 'dismissed', 'resolved') NULL,
                to_status      ENUM('open', 'done', 'dismissed', 'resolved') NOT NULL,
                changed_by     BIGINT UNSIGNED NULL,
                created_at     DATETIME        NOT NULL,
                PRIMARY KEY (id),
                KEY opportunity_status_history_opportunity (opportunity_id, created_at),
                CONSTRAINT opportunity_status_history_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities (id) ON DELETE CASCADE,
                CONSTRAINT opportunity_status_history_user_fk FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS project_opportunity_runs (
                project_id  BIGINT UNSIGNED NOT NULL,
                analyzed_at DATETIME        NOT NULL,
                detected    INT UNSIGNED    NOT NULL,
                PRIMARY KEY (project_id),
                CONSTRAINT project_opportunity_runs_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
