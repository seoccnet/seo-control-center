<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Crawler verification for bot logs (1.2.1): how many of each total's
 * requests came from the crawler's published IP ranges, and which crawlers
 * an import could verify at all (their ranges were readable for every
 * chunk). No IP address is stored. Imports read before stay unverified.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $columns = [
            ['bot_log_hits', 'verified_hits', 'INT UNSIGNED NOT NULL DEFAULT 0'],
            ['bot_log_imports', 'verified_bots', 'VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL'],
        ];
        foreach ($columns as [$table, $column, $definition]) {
            $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
            if ($exists === 0) {
                $db->statement(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
            }
        }
    }
};
