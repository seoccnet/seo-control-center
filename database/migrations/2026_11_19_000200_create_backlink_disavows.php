<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Disavow file builder (1.7.0): the domains a project's team decided to
 * disavow. Google replaces the whole list on every upload, so the file is
 * always written from this list, never from one review alone.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS backlink_disavows (
                project_id   BIGINT UNSIGNED NOT NULL,
                domain       VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                spam_score   TINYINT UNSIGNED NULL,
                added_by     BIGINT UNSIGNED NULL,
                added_at     DATETIME        NOT NULL,
                PRIMARY KEY (project_id, domain),
                CONSTRAINT backlink_disavows_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT backlink_disavows_added_by_fk FOREIGN KEY (added_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
