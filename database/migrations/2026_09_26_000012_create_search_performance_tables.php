<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Search Console performance data (DATABASE.md -> Search performance).
 *
 * Time series keyed by project and day. Dates are Search Console dates
 * (Pacific Time) exactly as Google reports them. Queries and page URLs are
 * stored once per project in dictionaries and referenced by id; the SHA-256
 * hash keeps the unique index small for long values.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS search_queries (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                query_hash BINARY(32)      NOT NULL,
                query      VARCHAR(500)    NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY search_queries_project_hash (project_id, query_hash),
                CONSTRAINT search_queries_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        // Pages known to the project. Later modules (crawler, page metrics) reuse this table.
        $db->statement(
            'CREATE TABLE IF NOT EXISTS pages (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                url_hash   BINARY(32)      NOT NULL,
                url        VARCHAR(2048)   NOT NULL,
                first_seen_at DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY pages_project_hash (project_id, url_hash),
                CONSTRAINT pages_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        // Daily totals for the whole property (these include anonymized queries,
        // so they are higher than the sum of the query rows).
        $db->statement(
            'CREATE TABLE IF NOT EXISTS search_console_daily (
                project_id  BIGINT UNSIGNED NOT NULL,
                date        DATE            NOT NULL,
                clicks      INT UNSIGNED    NOT NULL,
                impressions INT UNSIGNED    NOT NULL,
                position    DECIMAL(8,2)    NOT NULL,
                PRIMARY KEY (project_id, date),
                CONSTRAINT search_console_daily_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB',
        );

        // Primary keys start with (project_id, date) so date-range aggregations
        // read one contiguous index range; the secondary key serves history per query/page.
        $db->statement(
            'CREATE TABLE IF NOT EXISTS search_console_queries (
                project_id  BIGINT UNSIGNED NOT NULL,
                date        DATE            NOT NULL,
                query_id    BIGINT UNSIGNED NOT NULL,
                clicks      INT UNSIGNED    NOT NULL,
                impressions INT UNSIGNED    NOT NULL,
                position    DECIMAL(8,2)    NOT NULL,
                PRIMARY KEY (project_id, date, query_id),
                KEY search_console_queries_history (project_id, query_id, date),
                CONSTRAINT search_console_queries_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS search_console_pages (
                project_id  BIGINT UNSIGNED NOT NULL,
                date        DATE            NOT NULL,
                page_id     BIGINT UNSIGNED NOT NULL,
                clicks      INT UNSIGNED    NOT NULL,
                impressions INT UNSIGNED    NOT NULL,
                position    DECIMAL(8,2)    NOT NULL,
                PRIMARY KEY (project_id, date, page_id),
                KEY search_console_pages_history (project_id, page_id, date),
                CONSTRAINT search_console_pages_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB',
        );

        // Low-cardinality breakdowns (device, country).
        $db->statement(
            "CREATE TABLE IF NOT EXISTS search_console_dimensions (
                project_id  BIGINT UNSIGNED NOT NULL,
                date        DATE            NOT NULL,
                dimension   ENUM('device', 'country') NOT NULL,
                value       VARCHAR(32)     NOT NULL,
                clicks      INT UNSIGNED    NOT NULL,
                impressions INT UNSIGNED    NOT NULL,
                position    DECIMAL(8,2)    NOT NULL,
                PRIMARY KEY (project_id, dimension, date, value),
                CONSTRAINT search_console_dimensions_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_general_ci",
        );

        // Days whose query/page/device/country detail has been imported.
        $db->statement(
            'CREATE TABLE IF NOT EXISTS search_console_import_days (
                project_id  BIGINT UNSIGNED NOT NULL,
                date        DATE            NOT NULL,
                imported_at DATETIME        NOT NULL,
                PRIMARY KEY (project_id, date),
                CONSTRAINT search_console_import_days_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB',
        );

        // Import progress per project. site_url records which property the stored
        // data came from: linking a different property clears the old data.
        $db->statement(
            "CREATE TABLE IF NOT EXISTS search_console_sync (
                project_id         BIGINT UNSIGNED NOT NULL,
                site_url           VARCHAR(255)    NOT NULL,
                status             ENUM('pending', 'importing', 'ok', 'error') NOT NULL DEFAULT 'pending',
                totals_refreshed_at DATETIME       NULL,
                last_success_at    DATETIME        NULL,
                last_error         VARCHAR(500)    NULL,
                updated_at         DATETIME        NOT NULL,
                PRIMARY KEY (project_id),
                CONSTRAINT search_console_sync_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
