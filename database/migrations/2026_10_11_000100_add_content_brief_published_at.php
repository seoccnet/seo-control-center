<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * When a content brief was first marked Published (UTC), for the impact
 * countdown on the brief. Existing published briefs take their last edit as
 * the best available date; moving a brief back to draft clears it.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_briefs' AND COLUMN_NAME = 'published_at'",
        );
        if ($exists === 0) {
            $db->statement('ALTER TABLE content_briefs ADD COLUMN published_at DATETIME NULL AFTER status');
        }
        $db->statement("UPDATE content_briefs SET published_at = updated_at WHERE status = 'published' AND published_at IS NULL");
    }
};
