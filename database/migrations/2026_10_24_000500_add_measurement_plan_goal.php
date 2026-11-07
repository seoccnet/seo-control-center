<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * After-only goals (ADR-042, 0.39.1): a target value for the primary metric
 * over the period after the change, for changes whose targets had no data
 * before (a new page). It reads as reached or not reached, never as an
 * improvement. Plans saved before keep NULL (a before/after plan).
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['measurement_plans', 'goal']);
        if ($exists === 0) {
            $db->statement('ALTER TABLE measurement_plans ADD COLUMN goal DOUBLE NULL');
        }
    }
};
