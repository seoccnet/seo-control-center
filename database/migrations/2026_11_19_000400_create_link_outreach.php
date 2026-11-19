<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Link outreach tracker (1.8.0): sites the team asks for a link, one row per
 * project and domain, with where the idea came from and how far it got.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS link_outreach (
                project_id   BIGINT UNSIGNED NOT NULL,
                domain       VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                source       ENUM('mention', 'lost', 'broken', 'manual') NOT NULL,
                page_url     VARCHAR(2048)   NULL,
                target_url   VARCHAR(2048)   NULL,
                status       ENUM('to_contact', 'contacted', 'won', 'declined') NOT NULL DEFAULT 'to_contact',
                note         VARCHAR(500)    NULL,
                added_by     BIGINT UNSIGNED NULL,
                added_at     DATETIME        NOT NULL,
                updated_at   DATETIME        NOT NULL,
                PRIMARY KEY (project_id, domain),
                CONSTRAINT link_outreach_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT link_outreach_added_by_fk FOREIGN KEY (added_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
