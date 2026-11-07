<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Business details per crawled page (0.46.0): the structured-data business
 * a page states (types, name, address) and its phone numbers, as JSON, for
 * the local name, address and phone check. Pages crawled before stay NULL:
 * unknown, never "missing".
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['site_audit_pages', 'business_signals']);
        if ($exists === 0) {
            $db->statement('ALTER TABLE site_audit_pages ADD COLUMN business_signals VARCHAR(1000) NULL');
        }
    }
};
