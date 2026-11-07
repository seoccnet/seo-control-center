<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Site audit (1.2): per-project settings, the audits, every URL an audit
 * found (the crawl frontier and the results in one table, unique per audit
 * by the SHA-1 of the URL), the internal links between them and the issues
 * found when the crawl ends. Older audits are pruned, so these tables stay
 * at a few audits per project.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS site_audit_settings (
                project_id   BIGINT UNSIGNED   NOT NULL,
                workspace_id BIGINT UNSIGNED   NOT NULL,
                max_pages    SMALLINT UNSIGNED NOT NULL,
                weekly       TINYINT(1)        NOT NULL DEFAULT 0,
                updated_by   BIGINT UNSIGNED   NULL,
                created_at   DATETIME          NOT NULL,
                updated_at   DATETIME          NOT NULL,
                PRIMARY KEY (project_id),
                KEY site_audit_settings_weekly (weekly),
                CONSTRAINT site_audit_settings_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT site_audit_settings_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT site_audit_settings_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS site_audits (
                id            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
                public_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                project_id    BIGINT UNSIGNED   NOT NULL,
                workspace_id  BIGINT UNSIGNED   NOT NULL,
                status        ENUM('queued', 'running', 'completed', 'failed', 'cancelled') NOT NULL,
                trigger_type  ENUM('manual', 'scheduled') NOT NULL,
                start_url     VARCHAR(2048)     NOT NULL,
                max_pages     SMALLINT UNSIGNED NOT NULL,
                pages_crawled INT UNSIGNED      NOT NULL DEFAULT 0,
                pages_found   INT UNSIGNED      NOT NULL DEFAULT 0,
                health_score  TINYINT UNSIGNED  NULL,
                errors        INT UNSIGNED      NOT NULL DEFAULT 0,
                warnings      INT UNSIGNED      NOT NULL DEFAULT 0,
                notices       INT UNSIGNED      NOT NULL DEFAULT 0,
                error         VARCHAR(500)      NULL,
                started_by    BIGINT UNSIGNED   NULL,
                created_at    DATETIME          NOT NULL,
                started_at    DATETIME          NULL,
                finished_at   DATETIME          NULL,
                updated_at    DATETIME          NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY site_audits_public_id_unique (public_id),
                KEY site_audits_project (project_id, id),
                CONSTRAINT site_audits_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT site_audits_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT site_audits_started_by_fk FOREIGN KEY (started_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS site_audit_pages (
                id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
                audit_id       BIGINT UNSIGNED   NOT NULL,
                url            VARCHAR(2048)     NOT NULL,
                url_hash       CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                depth          SMALLINT UNSIGNED NOT NULL,
                state          ENUM('pending', 'done', 'blocked', 'skipped') NOT NULL,
                status_code    SMALLINT UNSIGNED NULL,
                content_type   VARCHAR(100)      NULL,
                redirect_to    VARCHAR(2048)     NULL,
                duration_ms    INT UNSIGNED      NULL,
                bytes          INT UNSIGNED      NULL,
                truncated      TINYINT(1)        NOT NULL DEFAULT 0,
                title          VARCHAR(500)      NULL,
                description    VARCHAR(1000)     NULL,
                h1_count       SMALLINT UNSIGNED NULL,
                h1             VARCHAR(500)      NULL,
                canonical      VARCHAR(2048)     NULL,
                noindex        TINYINT(1)        NOT NULL DEFAULT 0,
                word_count     INT UNSIGNED      NULL,
                internal_links SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                external_links SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                error          VARCHAR(500)      NULL,
                crawled_at     DATETIME          NULL,
                PRIMARY KEY (id),
                UNIQUE KEY site_audit_pages_url (audit_id, url_hash),
                KEY site_audit_pages_frontier (audit_id, state, depth, id),
                CONSTRAINT site_audit_pages_audit_fk FOREIGN KEY (audit_id) REFERENCES site_audits (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS site_audit_links (
                audit_id     BIGINT UNSIGNED NOT NULL,
                from_page_id BIGINT UNSIGNED NOT NULL,
                to_page_id   BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (audit_id, from_page_id, to_page_id),
                KEY site_audit_links_to (audit_id, to_page_id),
                CONSTRAINT site_audit_links_audit_fk FOREIGN KEY (audit_id) REFERENCES site_audits (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS site_audit_issues (
                id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                audit_id BIGINT UNSIGNED NOT NULL,
                page_id  BIGINT UNSIGNED NOT NULL,
                type     VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                severity ENUM('error', 'warning', 'notice') NOT NULL,
                detail   VARCHAR(500)    NULL,
                PRIMARY KEY (id),
                KEY site_audit_issues_type (audit_id, type),
                KEY site_audit_issues_severity (audit_id, severity),
                CONSTRAINT site_audit_issues_audit_fk FOREIGN KEY (audit_id) REFERENCES site_audits (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
