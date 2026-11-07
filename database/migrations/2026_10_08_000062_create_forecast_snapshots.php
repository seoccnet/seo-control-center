<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS forecast_calendar (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, project_id BIGINT UNSIGNED NOT NULL, event_name VARCHAR(100) NOT NULL, name_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, event_on DATE NOT NULL, known_on DATE NOT NULL, excluded_on DATE NULL, UNIQUE KEY calendar_occurrence (workspace_id, project_id, name_hash, event_on), FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS forecast_snapshots (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, project_id BIGINT UNSIGNED NOT NULL, issued_on DATE NOT NULL, horizon SMALLINT UNSIGNED NOT NULL, series_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, model_version VARCHAR(30) NOT NULL, payload JSON NOT NULL, actual_value DOUBLE NULL, actual_observed_at DATETIME NULL, created_at DATETIME NOT NULL, UNIQUE KEY immutable_origin (workspace_id, project_id, issued_on, horizon, series_hash, binding_hash, model_version), KEY scoped_history (workspace_id, project_id, issued_on, id), FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS forecast_tracking (project_id BIGINT UNSIGNED PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, enabled TINYINT(1) NOT NULL, last_issued_on DATE NULL, checked_on DATE NULL, FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
