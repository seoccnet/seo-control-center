<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS web_vitals_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, project_id BIGINT UNSIGNED NOT NULL, url_hash BINARY(32) NOT NULL, url VARCHAR(2048) NOT NULL, form_factor VARCHAR(10) NOT NULL, lcp DOUBLE NULL, inp DOUBLE NULL, cls DOUBLE NULL, period_from DATE NULL, period_to DATE NULL, fetched_at DATETIME NOT NULL, status VARCHAR(16) NOT NULL, UNIQUE KEY page_snapshot (project_id, url_hash, form_factor, fetched_at), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement("INSERT IGNORE INTO web_vitals_history (project_id, url_hash, url, form_factor, lcp, inp, cls, period_from, period_to, fetched_at, status) SELECT project_id, url_hash, url, 'PHONE', lcp, inp, cls, period_from, period_to, fetched_at, status FROM web_vitals");
        $db->statement('CREATE TABLE IF NOT EXISTS web_vitals_watches (project_id BIGINT UNSIGNED NOT NULL, url_hash BINARY(32) NOT NULL, url VARCHAR(2048) NOT NULL, form_factor VARCHAR(10) NOT NULL, attempted_at DATETIME NULL, PRIMARY KEY (project_id, url_hash, form_factor), KEY due_vitals (attempted_at), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
