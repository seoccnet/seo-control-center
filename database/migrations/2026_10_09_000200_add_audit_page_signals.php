<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Site audit page signals for structured data, Open Graph type and author
 * checks. NULL means the page was crawled before these were recorded.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        foreach ([
            'schema_types' => 'VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NULL',
            'og_type' => 'VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL',
            'has_author' => 'TINYINT(1) NULL',
        ] as $column => $definition) {
            if ((int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_audit_pages' AND COLUMN_NAME = ?", [$column]) === 0) {
                $db->statement('ALTER TABLE site_audit_pages ADD ' . $column . ' ' . $definition);
            }
        }
    }
};
