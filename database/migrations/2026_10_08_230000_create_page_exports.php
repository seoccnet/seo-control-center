<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement("CREATE TABLE IF NOT EXISTS page_exports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            project_id BIGINT UNSIGNED NOT NULL, workspace_id BIGINT UNSIGNED NOT NULL, requested_by BIGINT UNSIGNED NULL,
            search VARCHAR(200) NOT NULL, sort VARCHAR(20) NOT NULL, range_from DATE NOT NULL, range_to DATE NOT NULL,
            status ENUM('queued', 'running', 'ready', 'failed') NOT NULL,
            total_urls INT UNSIGNED NOT NULL, row_count INT UNSIGNED NOT NULL DEFAULT 0, file_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            cursor_key BIGINT NULL, cursor_url VARCHAR(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
            error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, completed_at DATETIME NULL, expires_at DATETIME NULL,
            KEY page_exports_project (project_id, id), KEY page_exports_due (status, expires_at), KEY page_exports_stale (status, updated_at),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
