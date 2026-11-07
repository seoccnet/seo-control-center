<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        if ((int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opportunities' AND COLUMN_NAME = 'raw_estimated_clicks'") === 0) {
            $db->statement('ALTER TABLE opportunities ADD raw_estimated_clicks DOUBLE NULL');
        }
        $db->statement('CREATE TABLE IF NOT EXISTS opportunity_outcomes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            project_id BIGINT UNSIGNED NOT NULL, workspace_id BIGINT UNSIGNED NOT NULL,
            opportunity_id BIGINT UNSIGNED NOT NULL, type VARCHAR(32) NOT NULL, subject VARCHAR(2100) NOT NULL,
            completed_on DATE NOT NULL, checkpoint SMALLINT UNSIGNED NOT NULL,
            predicted_clicks DOUBLE NULL, raw_predicted_clicks DOUBLE NULL, baseline_clicks INT UNSIGNED NULL, baseline_impressions INT UNSIGNED NULL, baseline_position DOUBLE NULL,
            actual_clicks INT UNSIGNED NULL, actual_impressions INT UNSIGNED NULL, actual_position DOUBLE NULL,
            annotation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL, status_history_id BIGINT UNSIGNED NULL,
            completion_source VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, measured_at DATETIME NULL, last_checked_at DATETIME NULL,
            UNIQUE KEY completion_checkpoint (project_id, opportunity_id, completed_on, checkpoint),
            KEY outcome_due (measured_at, last_checked_at, completed_on, checkpoint), KEY outcome_project (project_id, type, completed_on),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
