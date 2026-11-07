<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Source links (0.42.0): how many other sites a crawled page links to from
 * its text (editorial body links, once per site), for the "article without
 * source links" notice. Pages crawled before stay NULL: unknown, never zero.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['site_audit_pages', 'source_links']);
        if ($exists === 0) {
            $db->statement('ALTER TABLE site_audit_pages ADD COLUMN source_links SMALLINT UNSIGNED NULL');
        }
    }
};
