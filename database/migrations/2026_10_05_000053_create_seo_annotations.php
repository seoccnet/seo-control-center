<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS seo_annotations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, workspace_id BIGINT UNSIGNED NOT NULL, project_id BIGINT UNSIGNED NOT NULL, changed_on DATE NOT NULL, kind VARCHAR(20) NOT NULL, title VARCHAR(120) NOT NULL, note TEXT NOT NULL, url TEXT NULL, url_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME NULL, KEY project_dates (workspace_id, project_id, deleted_at, changed_on, id), KEY page_dates (project_id, url_hash, changed_on), FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE, FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
