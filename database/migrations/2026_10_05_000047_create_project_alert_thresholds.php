<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS project_alert_thresholds (project_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, traffic_drop TINYINT UNSIGNED NOT NULL DEFAULT 30, minimum_clicks INT UNSIGNED NOT NULL DEFAULT 100, ranking_cutoff TINYINT UNSIGNED NOT NULL DEFAULT 10, lost_domain_rank TINYINT UNSIGNED NOT NULL DEFAULT 30, FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
