<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS opportunity_tasks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, project_id BIGINT UNSIGNED NOT NULL, opportunity_id BIGINT UNSIGNED NOT NULL UNIQUE, status VARCHAR(20) NOT NULL, assignee CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL, due DATE NULL, notes TEXT NOT NULL, url TEXT NOT NULL, brief CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL, annotation CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, updated_at DATETIME NOT NULL, KEY task_filter (workspace_id, project_id, status, due), KEY task_assignee (workspace_id, project_id, assignee), FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE, FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
