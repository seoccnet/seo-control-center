<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Speed diagnostics (ADR-042, 0.38.0): why a lab run scored as it did, read
 * from the Lighthouse report the run already received (LCP element and
 * phases, render-blocking resources, top diagnostics, resource summary,
 * test configuration, and a hash and size of the raw report). Stored as
 * bounded JSON; runs from before stay without diagnostics.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['lab_runs', 'diagnostics']);
        if ($exists === 0) {
            $db->statement('ALTER TABLE lab_runs ADD COLUMN diagnostics MEDIUMTEXT NULL');
        }
    }
};
