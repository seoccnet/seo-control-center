<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Monthly rollups of the query and page rows (DATABASE.md -> Search
 * performance). Long date ranges sum whole months from these tables and only
 * the partial months at the edges from the daily rows: 16 months read about
 * five times fewer rows. position_sum is SUM(position * impressions), so
 * averages stay weighted by impressions.
 *
 * search_console_rollup_months marks the months whose rollup matches the
 * daily rows. Importing a day removes its month's mark in the same
 * transaction; unmarked months are read from the daily rows.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        foreach (['queries' => 'query_id', 'pages' => 'page_id'] as $table => $key) {
            $db->statement(
                "CREATE TABLE IF NOT EXISTS search_console_{$table}_monthly (
                    project_id   BIGINT UNSIGNED NOT NULL,
                    month        DATE            NOT NULL,
                    {$key}       BIGINT UNSIGNED NOT NULL,
                    clicks       INT UNSIGNED    NOT NULL,
                    impressions  BIGINT UNSIGNED NOT NULL,
                    position_sum DECIMAL(20,2)   NOT NULL,
                    PRIMARY KEY (project_id, month, {$key}),
                    CONSTRAINT search_console_{$table}_monthly_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
                ) ENGINE=InnoDB",
            );
        }
        $db->statement(
            'CREATE TABLE IF NOT EXISTS search_console_rollup_months (
                project_id   BIGINT UNSIGNED NOT NULL,
                month        DATE            NOT NULL,
                refreshed_at DATETIME        NOT NULL,
                PRIMARY KEY (project_id, month),
                CONSTRAINT search_console_rollup_months_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB',
        );
    }
};
