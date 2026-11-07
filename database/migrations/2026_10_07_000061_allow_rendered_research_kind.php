<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement("ALTER TABLE research_results MODIFY kind ENUM('ranked', 'gap', 'ideas', 'competitors', 'overview', 'llm', 'llm_prompts', 'bl_profiles', 'bl_domains', 'bl_new', 'bl_lost', 'bl_anchors', 'bl_gap', 'bl_links', 'bl_lost_links', 'rendered_audit') NOT NULL");
        $db->statement('CREATE TABLE IF NOT EXISTS rendered_audit_history (request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, project_id BIGINT UNSIGNED NOT NULL, params JSON NOT NULL, items MEDIUMTEXT NOT NULL, fetched_at DATETIME NOT NULL, KEY project_history (project_id, fetched_at), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
