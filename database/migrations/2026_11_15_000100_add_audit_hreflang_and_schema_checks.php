<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Hreflang and structured data checks per crawled page (1.2.0): the
 * page's hreflang alternates and html lang attribute, and the problems
 * found in its JSON-LD, both as JSON. Pages crawled before stay NULL:
 * unknown, never "missing".
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $columns = [
            'hreflang_links' => 'TEXT NULL',
            'html_lang' => 'VARCHAR(35) NULL',
            'schema_problems' => 'VARCHAR(4000) NULL',
        ];
        foreach ($columns as $column => $definition) {
            $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['site_audit_pages', $column]);
            if ($exists === 0) {
                $db->statement(sprintf('ALTER TABLE site_audit_pages ADD COLUMN %s %s', $column, $definition));
            }
        }
    }
};
