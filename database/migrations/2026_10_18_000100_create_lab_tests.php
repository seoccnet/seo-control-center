<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Saved lab test profiles and their runs (ADR-040, F2). A profile fixes a
 * page, a device (mobile or desktop) and a source: Google PageSpeed
 * Insights (free, the workspace's Google API key) or DataForSEO Lighthouse
 * (paid, workspace budget). Runs keep Lighthouse's performance score and
 * lab metrics; the newest 50 runs per profile are kept. Lab results are
 * stored apart from Chrome UX Report field data and never merged with it.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS lab_profiles (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            project_id   BIGINT UNSIGNED NOT NULL,
            url          VARCHAR(2048) NOT NULL,
            url_hash     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            device       VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            source       VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            weekly       TINYINT(1) NOT NULL DEFAULT 0,
            next_run_at  DATETIME NULL,
            created_by   BIGINT UNSIGNED NULL,
            created_at   DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY lab_profiles_public (public_id),
            UNIQUE KEY lab_profiles_setup (project_id, url_hash, device, source),
            KEY lab_profiles_due (weekly, next_run_at),
            CONSTRAINT lab_profiles_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT lab_profiles_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS lab_runs (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            profile_id         BIGINT UNSIGNED NOT NULL,
            project_id         BIGINT UNSIGNED NOT NULL,
            workspace_id       BIGINT UNSIGNED NOT NULL,
            status             VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            trigger_type       VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            requested_by       BIGINT UNSIGNED NULL,
            task_id            VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            polls              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            score              DOUBLE NULL,
            lcp_ms             DOUBLE NULL,
            cls                DOUBLE NULL,
            tbt_ms             DOUBLE NULL,
            fcp_ms             DOUBLE NULL,
            si_ms              DOUBLE NULL,
            lighthouse_version VARCHAR(20) NULL,
            error              VARCHAR(500) NULL,
            cost               DOUBLE NULL,
            created_at         DATETIME NOT NULL,
            finished_at        DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY lab_runs_public (public_id),
            KEY lab_runs_profile (profile_id, created_at),
            CONSTRAINT lab_runs_profile_fk FOREIGN KEY (profile_id) REFERENCES lab_profiles (id) ON DELETE CASCADE,
            CONSTRAINT lab_runs_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
