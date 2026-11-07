<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS workspace_report_branding (workspace_id BIGINT UNSIGNED PRIMARY KEY, product_name VARCHAR(60) NULL, accent_color CHAR(7) NULL, report_footer VARCHAR(300) NULL, logo MEDIUMBLOB NULL, logo_type VARCHAR(20) NULL, FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS project_report_sections (project_id BIGINT UNSIGNED PRIMARY KEY, sections JSON NOT NULL, FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
