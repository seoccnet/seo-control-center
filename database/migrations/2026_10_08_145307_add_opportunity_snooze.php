<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        if ((int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opportunities' AND COLUMN_NAME = 'snoozed_until'") === 0) {
            $db->statement('ALTER TABLE opportunities ADD snoozed_until DATE NULL');
        }
        foreach ([['opportunities', 'status', 'NOT NULL'], ['opportunity_status_history', 'from_status', 'NULL'], ['opportunity_status_history', 'to_status', 'NOT NULL']] as [$table, $column, $nullable]) {
            $type = (string) $db->fetchValue('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
            if (!str_contains($type, 'snoozed')) {
                $db->statement("ALTER TABLE {$table} MODIFY {$column} ENUM('open', 'done', 'dismissed', 'resolved', 'snoozed') {$nullable}");
            }
        }
    }
};
