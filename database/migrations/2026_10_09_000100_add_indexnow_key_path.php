<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * IndexNow keys served from a subdirectory (for example a WordPress site at
 * /blog/): the verified key path, "/" for the site root. A key there only
 * covers URLs under that path.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        if ((int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'indexnow_settings' AND COLUMN_NAME = 'key_path'") === 0) {
            $db->statement("ALTER TABLE indexnow_settings ADD key_path VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '/' AFTER host");
        }
    }
};
