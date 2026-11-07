<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * When the link suggestions of an audit were last built (ADR-040, F1;
 * 0.25.2), so an empty list can be told apart from one not built yet.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_audits' AND COLUMN_NAME = 'links_built_at'");
        if ($exists === 0) {
            $db->statement('ALTER TABLE site_audits ADD COLUMN links_built_at DATETIME NULL');
        }
    }
};
