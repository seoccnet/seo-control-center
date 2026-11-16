<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Imported ranking history (1.3.0): `source` is "import" for positions
 * brought in from another tool's export and NULL for SEOcc's own checks,
 * so imported history can be told apart. An import never overwrites a
 * day SEOcc checked itself; a later check of an imported day replaces it.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['ranking_snapshots', 'source']);
        if ($exists === 0) {
            $db->statement('ALTER TABLE ranking_snapshots ADD COLUMN source VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL');
        }
    }
};
