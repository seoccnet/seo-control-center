<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;
use SeoControlCenter\Core\Database\Migrations\Schema;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement("ALTER TABLE research_results MODIFY kind ENUM('ranked', 'gap', 'ideas', 'competitors', 'overview', 'llm', 'llm_prompts',
            'bl_profiles', 'bl_domains', 'bl_new', 'bl_lost', 'bl_anchors', 'bl_gap', 'bl_links', 'bl_lost_links') NOT NULL");
        Schema::addColumn($db, 'backlink_settings', 'monitor_lost', 'TINYINT(1) NOT NULL DEFAULT 0');
        Schema::addColumn($db, 'backlink_settings', 'last_lost_checked_on', 'DATE NULL');
    }
};
