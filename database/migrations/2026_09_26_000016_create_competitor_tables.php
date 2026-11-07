<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Competitors (0.5, part 1): competing domains per project, their
 * positions for the project's keywords (taken from the SERPs that rank
 * checks already fetch) and daily aggregates like ranking_daily.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS competitors (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                project_id BIGINT UNSIGNED NOT NULL,
                domain     VARCHAR(253) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
                source     ENUM('manual', 'discovered') NOT NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY competitors_public_id_unique (public_id),
                UNIQUE KEY competitors_project_domain (project_id, domain),
                CONSTRAINT competitors_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT competitors_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS competitor_rankings (
                competitor_id      BIGINT UNSIGNED NOT NULL,
                project_keyword_id BIGINT UNSIGNED NOT NULL,
                device             ENUM('desktop', 'mobile') NOT NULL,
                checked_on         DATE            NOT NULL,
                position           TINYINT UNSIGNED NULL,
                url                VARCHAR(2048)   NULL,
                PRIMARY KEY (competitor_id, device, checked_on, project_keyword_id),
                KEY competitor_rankings_keyword (project_keyword_id, device, checked_on),
                CONSTRAINT competitor_rankings_competitor_fk FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE CASCADE,
                CONSTRAINT competitor_rankings_keyword_fk FOREIGN KEY (project_keyword_id) REFERENCES project_keywords (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS competitor_daily (
                competitor_id    BIGINT UNSIGNED NOT NULL,
                device           ENUM('desktop', 'mobile') NOT NULL,
                date             DATE            NOT NULL,
                keywords         INT UNSIGNED    NOT NULL,
                ranked           INT UNSIGNED    NOT NULL,
                top3             INT UNSIGNED    NOT NULL,
                top10            INT UNSIGNED    NOT NULL,
                average_position DECIMAL(6,2)    NULL,
                visibility       DECIMAL(8,4)    NOT NULL,
                PRIMARY KEY (competitor_id, device, date),
                CONSTRAINT competitor_daily_competitor_fk FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
