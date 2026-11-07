<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS google_inspections (project_id BIGINT UNSIGNED NOT NULL, url_hash BINARY(32) NOT NULL, binding BINARY(32) NOT NULL, url TEXT NOT NULL, watched TINYINT(1) NOT NULL DEFAULT 0, result JSON NULL, checked_at DATETIME NULL, attempted_at DATETIME NULL, error VARCHAR(300) NULL, last_job CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL, PRIMARY KEY (project_id, url_hash), KEY due_inspections (watched, attempted_at), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS google_inspection_changes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, project_id BIGINT UNSIGNED NOT NULL, binding BINARY(32) NOT NULL, url TEXT NOT NULL, before_verdict VARCHAR(16) NOT NULL, after_verdict VARCHAR(16) NOT NULL, canonical_changed TINYINT(1) NOT NULL, checked_at DATETIME NOT NULL, KEY recent_changes (project_id, checked_at), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
