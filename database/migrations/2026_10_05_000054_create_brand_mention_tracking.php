<?php

declare(strict_types=1);
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS brand_mention_settings (project_id BIGINT UNSIGNED PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, brand_name VARCHAR(120) NOT NULL, location_code INT NOT NULL, language_code VARCHAR(10) NOT NULL, weekly TINYINT(1) NOT NULL DEFAULT 0, configured_by BIGINT UNSIGNED NOT NULL, attempted_at DATETIME NULL, updated_at DATETIME NOT NULL, KEY due (weekly, attempted_at), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE, FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY (configured_by) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS brand_mention_checks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, project_id BIGINT UNSIGNED NOT NULL, workspace_id BIGINT UNSIGNED NOT NULL, config_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, status VARCHAR(20) NOT NULL, step TINYINT UNSIGNED NOT NULL DEFAULT 0, metrics JSON NOT NULL, answers JSON NOT NULL, error VARCHAR(500) NULL, requested_by BIGINT UNSIGNED NOT NULL, scheduled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY history (workspace_id, project_id, config_key, id), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE, FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
