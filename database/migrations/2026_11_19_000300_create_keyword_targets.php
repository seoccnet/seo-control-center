<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Target page per tracked keyword (1.8.0): the page the team wants to rank
 * for the keyword, compared with the page Google ranks.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS keyword_targets (
                project_keyword_id BIGINT UNSIGNED NOT NULL,
                project_id         BIGINT UNSIGNED NOT NULL,
                url                VARCHAR(2048)   NOT NULL,
                set_by             BIGINT UNSIGNED NULL,
                set_at             DATETIME        NOT NULL,
                PRIMARY KEY (project_keyword_id),
                KEY keyword_targets_project (project_id),
                CONSTRAINT keyword_targets_keyword_fk FOREIGN KEY (project_keyword_id) REFERENCES project_keywords (id) ON DELETE CASCADE,
                CONSTRAINT keyword_targets_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT keyword_targets_set_by_fk FOREIGN KEY (set_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
