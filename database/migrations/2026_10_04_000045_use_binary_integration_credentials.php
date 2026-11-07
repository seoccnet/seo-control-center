<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('ALTER TABLE crux_settings MODIFY api_key BLOB NOT NULL');
        $db->statement('ALTER TABLE ga4_settings MODIFY credentials BLOB NOT NULL');
    }
};
