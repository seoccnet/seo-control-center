<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['project_opportunity_runs', 'coverage'],
        );
        if ($exists === 0) {
            $db->statement('ALTER TABLE project_opportunity_runs ADD COLUMN coverage JSON NULL');
        }
    }
};
