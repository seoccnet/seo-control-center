<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Titles Google rewrites (1.7.0): the title Google showed for the project's
 * own result in a rank check, so it can be compared with the page's title.
 * NULL for checks without an own result and for checks before 1.7.0.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['ranking_snapshots', 'shown_title']);
        if ($exists === 0) {
            $db->statement('ALTER TABLE ranking_snapshots ADD COLUMN shown_title VARCHAR(300) NULL');
        }
    }
};
