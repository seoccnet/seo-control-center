<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Database\Migrations;

use SeoControlCenter\Core\Database\Connection;

/** Additive DDL that can resume after MySQL's implicit commits. */
final class Schema
{
    public static function addColumn(Connection $db, string $table, string $column, string $definition): void
    {
        self::identifier($table);
        self::identifier($column);
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]) === 0) {
            $db->statement(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
        }
    }

    public static function addIndex(Connection $db, string $table, string $index, string $columns): void
    {
        self::identifier($table);
        self::identifier($index);
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$table, $index]) === 0) {
            $db->statement(sprintf('ALTER TABLE `%s` ADD KEY `%s` (%s)', $table, $index, $columns));
        }
    }

    private static function identifier(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) {
            throw new MigrationException('Invalid schema identifier.');
        }
    }
}
