<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS local_locations (project_id BIGINT UNSIGNED NOT NULL, public_id CHAR(36) NOT NULL, name VARCHAR(200) NOT NULL, cid VARCHAR(20) NOT NULL, latitude DECIMAL(9,6) NOT NULL, longitude DECIMAL(9,6) NOT NULL, zoom TINYINT UNSIGNED NOT NULL, language VARCHAR(10) NOT NULL, keywords JSON NOT NULL, weekly TINYINT(1) NOT NULL DEFAULT 0, checked_on DATE NULL, PRIMARY KEY (project_id, public_id), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS local_location_checks (project_id BIGINT UNSIGNED NOT NULL, location_public_id CHAR(36) NOT NULL, date DATE NOT NULL, result JSON NOT NULL, PRIMARY KEY (project_id, location_public_id, date), FOREIGN KEY (project_id, location_public_id) REFERENCES local_locations(project_id, public_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS local_reviews (project_id BIGINT UNSIGNED NOT NULL, location_key VARCHAR(36) NOT NULL, state JSON NOT NULL, PRIMARY KEY (project_id, location_key), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
