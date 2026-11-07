<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;
use SeoControlCenter\Core\Database\Migrations\Schema;

/*
 * Workspaces can be deleted in the browser (1.0). Like projects, they are
 * soft-deleted: hidden from everyone and excluded from access checks and the
 * licence count, while the data stays in the database.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        Schema::addColumn($db, 'workspaces', 'deleted_at', 'DATETIME NULL AFTER updated_at');
        Schema::addIndex($db, 'workspaces', 'workspaces_deleted', 'deleted_at');
    }
};
