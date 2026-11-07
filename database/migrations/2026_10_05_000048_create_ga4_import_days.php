<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;
use SeoControlCenter\Domain\Shared\DateRange;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS ga4_import_days (project_id BIGINT UNSIGNED NOT NULL, date DATE NOT NULL, currency VARCHAR(16) NOT NULL, timezone VARCHAR(100) NOT NULL, limited TINYINT(1) NOT NULL, thresholded TINYINT(1) NOT NULL, PRIMARY KEY (project_id, date), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        foreach ($db->fetchAll('SELECT project_id, period_from, period_to, currency, timezone, limited, thresholded FROM ga4_project_properties WHERE fetched_at IS NOT NULL') as $row) {
            $range = new DateRange((string) $row['period_from'], (string) $row['period_to']);
            foreach ($range->eachDay() as $date) {
                $db->execute('INSERT IGNORE INTO ga4_import_days (project_id, date, currency, timezone, limited, thresholded) VALUES (?, ?, ?, ?, ?, ?)', [$row['project_id'], $date, $row['currency'], $row['timezone'], $row['limited'], $row['thresholded']]);
            }
        }
    }
};
