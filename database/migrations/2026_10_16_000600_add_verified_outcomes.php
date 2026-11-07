<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Before/after watches and verified outcomes (ADR-039, E2): a per-project
 * page watch limit set per workspace, why a watch exists and when its
 * "after" check is due, and per outcome the page it concerns, whether the
 * work was verified, and the other changes that overlapped its window.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $columns = [
            ['workspaces', 'page_watch_limit', 'ADD COLUMN page_watch_limit SMALLINT UNSIGNED NOT NULL DEFAULT 50'],
            ['page_watches', 'reason', "ADD COLUMN reason VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'watch'"],
            ['page_watches', 'after_due_at', 'ADD COLUMN after_due_at DATETIME NULL'],
            ['opportunity_outcomes', 'page_url', 'ADD COLUMN page_url VARCHAR(2048) NULL'],
            ['opportunity_outcomes', 'implementation', "ADD COLUMN implementation VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unverified'"],
            ['opportunity_outcomes', 'implementation_source', 'ADD COLUMN implementation_source VARCHAR(255) NULL'],
            ['opportunity_outcomes', 'implementation_at', 'ADD COLUMN implementation_at DATETIME NULL'],
            ['opportunity_outcomes', 'overlaps', 'ADD COLUMN overlaps TEXT NULL'],
        ];
        foreach ($columns as [$table, $column, $alter]) {
            $exists = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            );
            if ($exists === 0) {
                $db->statement('ALTER TABLE ' . $table . ' ' . $alter);
            }
        }
        $index = (int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_watches' AND INDEX_NAME = 'page_watches_after_due'");
        if ($index === 0) {
            $db->statement('ALTER TABLE page_watches ADD KEY page_watches_after_due (after_due_at)');
        }
    }
};
