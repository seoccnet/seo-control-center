<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Conversion diagnostics (ADR-042, 0.32.0): the business events a project
 * counts as success, their daily GA4 event counts (site-wide and Organic
 * Search, zero rows included so a covered day is known), the last event
 * import per project, and owner-triggered realtime test checks.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS business_events (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id  BIGINT UNSIGNED NOT NULL,
            project_id    BIGINT UNSIGNED NOT NULL,
            kind          VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            event_name    VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            label         VARCHAR(120) NOT NULL,
            created_by    BIGINT UNSIGNED NULL,
            created_at    DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY business_events_public (public_id),
            UNIQUE KEY business_events_name (project_id, event_name),
            CONSTRAINT business_events_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT business_events_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS ga4_event_days (
            project_id    BIGINT UNSIGNED NOT NULL,
            date          DATE NOT NULL,
            event_name    VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            organic_count BIGINT UNSIGNED NOT NULL,
            total_count   BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (project_id, event_name, date),
            CONSTRAINT ga4_event_days_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS ga4_event_imports (
            project_id    BIGINT UNSIGNED NOT NULL,
            period_from   DATE NULL,
            period_to     DATE NULL,
            timezone      VARCHAR(64) NULL,
            limited       TINYINT(1) NOT NULL DEFAULT 0,
            thresholded   TINYINT(1) NOT NULL DEFAULT 0,
            fetched_at    DATETIME NULL,
            error         VARCHAR(500) NULL,
            error_at      DATETIME NULL,
            PRIMARY KEY (project_id),
            CONSTRAINT ga4_event_imports_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS business_event_checks (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id  BIGINT UNSIGNED NOT NULL,
            project_id    BIGINT UNSIGNED NOT NULL,
            status        VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            note          VARCHAR(500) NOT NULL,
            result        TEXT NULL,
            error         VARCHAR(500) NULL,
            requested_by  BIGINT UNSIGNED NULL,
            requested_at  DATETIME NOT NULL,
            finished_at   DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY business_event_checks_public (public_id),
            KEY business_event_checks_project (project_id, requested_at),
            CONSTRAINT business_event_checks_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT business_event_checks_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
