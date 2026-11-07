<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS search_console_query_pages (
            project_id BIGINT UNSIGNED NOT NULL,
            date DATE NOT NULL,
            query_id BIGINT UNSIGNED NOT NULL,
            page_id BIGINT UNSIGNED NOT NULL,
            clicks INT UNSIGNED NOT NULL,
            impressions INT UNSIGNED NOT NULL,
            position DECIMAL(8,2) NOT NULL,
            PRIMARY KEY (project_id, date, query_id, page_id),
            KEY query_pages_page (project_id, page_id, date),
            CONSTRAINT query_pages_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS search_console_query_page_days (
            project_id BIGINT UNSIGNED NOT NULL,
            date DATE NOT NULL,
            imported_at DATETIME NOT NULL,
            PRIMARY KEY (project_id, date),
            CONSTRAINT query_page_days_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
