<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        if ((int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opportunities' AND COLUMN_NAME = 'estimated_clicks'") === 0) {
            $db->statement('ALTER TABLE opportunities ADD estimated_clicks DOUBLE NULL');
        }
    }
};
