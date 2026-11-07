<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Internal-link classes (ADR-042, 0.35.0): what kind of link each crawled
 * internal link is (in the text, related-article card, product card,
 * template, other) with a confidence, and how many link suggestions were
 * left out because the paragraph is about something else than the target.
 * Audits crawled before stay without a class ("not recorded").
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $columns = [
            ['site_audit_links', 'link_class', 'ADD COLUMN link_class VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL'],
            ['site_audit_links', 'link_confidence', 'ADD COLUMN link_confidence VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NULL'],
            ['site_audits', 'links_left_out', 'ADD COLUMN links_left_out INT UNSIGNED NULL'],
        ];
        foreach ($columns as [$table, $column, $change]) {
            $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
            if ($exists === 0) {
                $db->statement('ALTER TABLE ' . $table . ' ' . $change);
            }
        }
    }
};
