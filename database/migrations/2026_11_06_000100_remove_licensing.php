<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * SEOcc is open source under AGPL-3.0 (ADR-052): no licence state is kept any
 * more, so a stored licence key leaves the database. Background checks of the
 * old licence and update service that are still queued are dropped.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS license_state');
        $db->execute("DELETE FROM jobs WHERE type IN ('license.refresh', 'updates.check') AND status IN ('queued', 'failed')");
    }
};
