<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Site health monitoring (1.3.0): the latest uptime, certificate, domain,
 * robots.txt and sitemap check of each project's site, and the events they
 * produced (down, recovered, expiring, changed). An event's dedupe_key makes
 * it be recorded once, so a check that runs again never repeats an alert.
 * A project without a state row is monitored with the defaults.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS site_health_state (
            project_id         BIGINT UNSIGNED NOT NULL,
            workspace_id       BIGINT UNSIGNED NOT NULL,
            enabled            TINYINT(1) NOT NULL DEFAULT 1,
            uptime_status      VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
            uptime_code        SMALLINT UNSIGNED NULL,
            uptime_ms          INT UNSIGNED NULL,
            uptime_error       VARCHAR(300) NULL,
            failures           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            down_since         DATETIME NULL,
            uptime_checked_at  DATETIME NULL,
            ssl_expires_at     DATETIME NULL,
            ssl_issuer         VARCHAR(200) NULL,
            ssl_error          VARCHAR(300) NULL,
            domain_name        VARCHAR(253) NULL,
            domain_expires_at  DATETIME NULL,
            domain_registrar   VARCHAR(200) NULL,
            domain_error       VARCHAR(300) NULL,
            domain_checked_at  DATETIME NULL,
            robots_status      SMALLINT UNSIGNED NULL,
            robots_hash        CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
            robots_text        MEDIUMTEXT NULL,
            robots_changed_at  DATETIME NULL,
            sitemap_urls       INT UNSIGNED NULL,
            sitemap_changed_at DATETIME NULL,
            daily_checked_at   DATETIME NULL,
            updated_at         DATETIME NOT NULL,
            PRIMARY KEY (project_id),
            KEY site_health_state_uptime (enabled, uptime_checked_at),
            CONSTRAINT site_health_state_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT site_health_state_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS site_health_events (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            project_id  BIGINT UNSIGNED NOT NULL,
            kind        VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            dedupe_key  VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            title       VARCHAR(200) NOT NULL,
            detail      VARCHAR(2000) NOT NULL,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY site_health_events_public (public_id),
            UNIQUE KEY site_health_events_dedupe (project_id, dedupe_key),
            KEY site_health_events_recent (project_id, created_at),
            CONSTRAINT site_health_events_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
