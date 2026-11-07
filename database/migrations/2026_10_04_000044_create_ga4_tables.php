<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS ga4_settings (workspace_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, credentials TEXT NOT NULL,
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS ga4_project_properties (project_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, property_id VARCHAR(20) NOT NULL,
            period_from DATE NULL, period_to DATE NULL, currency VARCHAR(7) NULL, timezone VARCHAR(100) NULL, limited TINYINT(1) NOT NULL DEFAULT 0,
            thresholded TINYINT(1) NOT NULL DEFAULT 0, fetched_at DATETIME NULL,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS ga4_landing_pages (project_id BIGINT UNSIGNED NOT NULL, date DATE NOT NULL, url_hash BINARY(32) NOT NULL, url VARCHAR(2048) NOT NULL,
            sessions INT UNSIGNED NOT NULL, conversions DECIMAL(20,6) NOT NULL, revenue DECIMAL(20,6) NOT NULL,
            PRIMARY KEY (project_id, date, url_hash), KEY ga4_page (project_id, url_hash, date),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
