<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Local ranking grids (1.3): the Google Maps position of one local keyword
 * at size × size points around the business's location. `positions` maps
 * the point index to the position (null = not found); points still missing
 * are checked by the running job.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS local_grids (
                id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
                project_id       BIGINT UNSIGNED  NOT NULL,
                local_keyword_id BIGINT UNSIGNED  NOT NULL,
                checked_on       DATE             NOT NULL,
                size             TINYINT UNSIGNED NOT NULL,
                spacing          INT UNSIGNED     NOT NULL,
                latitude         DECIMAL(9,6)     NOT NULL,
                longitude        DECIMAL(9,6)     NOT NULL,
                zoom             TINYINT UNSIGNED NOT NULL,
                positions        JSON             NOT NULL,
                status           ENUM('queued', 'done', 'failed') NOT NULL,
                error            VARCHAR(500)     NULL,
                created_at       DATETIME         NOT NULL,
                PRIMARY KEY (id),
                KEY local_grids_keyword (local_keyword_id, id),
                KEY local_grids_project_status (project_id, status),
                CONSTRAINT local_grids_keyword_fk FOREIGN KEY (local_keyword_id) REFERENCES local_keywords (id) ON DELETE CASCADE,
                CONSTRAINT local_grids_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
