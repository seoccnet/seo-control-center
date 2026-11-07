<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS page_watches (project_id BIGINT UNSIGNED NOT NULL, url_hash BINARY(32) NOT NULL, url TEXT NOT NULL, generation CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, snapshot JSON NULL, checked_at DATETIME NULL, attempted_at DATETIME NULL, error VARCHAR(300) NULL, last_job CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL, PRIMARY KEY (project_id, url_hash), KEY due_watches (attempted_at), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS page_watch_changes (public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, project_id BIGINT UNSIGNED NOT NULL, url TEXT NOT NULL, before_snapshot JSON NOT NULL, after_snapshot JSON NOT NULL, before_at DATETIME NOT NULL, detected_at DATETIME NOT NULL, significant TINYINT(1) NOT NULL, annotation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL, KEY recent_page_changes (project_id, detected_at), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
