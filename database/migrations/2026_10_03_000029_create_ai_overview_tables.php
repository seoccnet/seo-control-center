<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * AI Overviews (1.2), taken from the SERPs that rank checks already fetch.
 * `ai_overview_snapshots` keeps one row per keyword, device and check day
 * (history: whether an AI Overview was shown and where the project's own
 * domain was cited). `ai_overview_references` holds the sources of the
 * latest check only and is replaced on every check.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS ai_overview_snapshots (
                project_keyword_id BIGINT UNSIGNED  NOT NULL,
                project_id         BIGINT UNSIGNED  NOT NULL,
                device             ENUM('desktop', 'mobile') NOT NULL,
                checked_on         DATE             NOT NULL,
                present            TINYINT(1)       NOT NULL,
                reference_count    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                own_position       TINYINT UNSIGNED NULL,
                own_url            VARCHAR(2048)    NULL,
                PRIMARY KEY (project_keyword_id, device, checked_on),
                KEY ai_overview_snapshots_project_day (project_id, device, checked_on),
                CONSTRAINT ai_overview_snapshots_keyword_fk FOREIGN KEY (project_keyword_id) REFERENCES project_keywords (id) ON DELETE CASCADE,
                CONSTRAINT ai_overview_snapshots_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS ai_overview_references (
                project_keyword_id BIGINT UNSIGNED  NOT NULL,
                project_id         BIGINT UNSIGNED  NOT NULL,
                device             ENUM('desktop', 'mobile') NOT NULL,
                position           TINYINT UNSIGNED NOT NULL,
                checked_on         DATE             NOT NULL,
                domain             VARCHAR(255)     NOT NULL,
                url                VARCHAR(2048)    NOT NULL,
                title              VARCHAR(300)     NULL,
                PRIMARY KEY (project_keyword_id, device, position),
                KEY ai_overview_references_project (project_id, device, domain),
                CONSTRAINT ai_overview_references_keyword_fk FOREIGN KEY (project_keyword_id) REFERENCES project_keywords (id) ON DELETE CASCADE,
                CONSTRAINT ai_overview_references_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
