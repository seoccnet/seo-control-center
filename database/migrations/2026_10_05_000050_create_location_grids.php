<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS local_location_grids (project_id BIGINT UNSIGNED NOT NULL, location_public_id CHAR(36) NOT NULL, public_id CHAR(36) NOT NULL, keyword VARCHAR(100) NOT NULL, checked_on DATE NOT NULL, size TINYINT UNSIGNED NOT NULL, spacing INT UNSIGNED NOT NULL, positions JSON NOT NULL, status VARCHAR(10) NOT NULL, error VARCHAR(500) NULL, PRIMARY KEY (project_id, public_id), KEY branch_history (project_id, location_public_id, public_id), FOREIGN KEY (project_id, location_public_id) REFERENCES local_locations(project_id, public_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
