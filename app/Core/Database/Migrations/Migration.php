<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Database\Migrations;

use SeoControlCenter\Core\Database\Connection;

/**
 * A forward-only schema migration (ADR-018).
 *
 * Migration files live in database/migrations (or a module's migrations
 * directory), are named `YYYY_MM_DD_HHMMSS_description.php`, and return an
 * instance of this interface (usually an anonymous class).
 * up() must be safe to retry after any statement: MySQL DDL commits implicitly.
 * Use CREATE TABLE IF NOT EXISTS, Schema::addColumn/addIndex and replay-safe
 * data writes. Released files remain immutable; the audited LegacyChecksums
 * catalog is a one-time compatibility exception for the resumability repair.
 */
interface Migration
{
    public function up(Connection $db): void;
}
