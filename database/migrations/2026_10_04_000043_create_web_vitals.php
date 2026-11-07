<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS crux_settings (workspace_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, api_key TEXT NOT NULL,
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement("CREATE TABLE IF NOT EXISTS web_vitals (project_id BIGINT UNSIGNED NOT NULL, url_hash BINARY(32) NOT NULL, url VARCHAR(2048) NOT NULL,
            lcp DOUBLE NULL, inp DOUBLE NULL, cls DOUBLE NULL, period_from DATE NULL, period_to DATE NULL, fetched_at DATETIME NOT NULL,
            status ENUM('available','no_data') NOT NULL, PRIMARY KEY (project_id, url_hash),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
