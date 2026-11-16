<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Mobile and desktop together (1.6.0): with `both_devices`, each rank check
 * also covers the device other than `device`, which stays the primary one
 * for dashboards, competitors and alerts. Off for existing projects.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['project_ranking_settings', 'both_devices']);
        if ($exists === 0) {
            $db->statement('ALTER TABLE project_ranking_settings ADD COLUMN both_devices TINYINT(1) NOT NULL DEFAULT 0');
        }
    }
};
