<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Saved keyword clusters with page ownership and query-to-page overlap
 * (ADR-037, C2). Recomputed by each opportunity analysis; a cluster keeps
 * its public ID while its members mostly stay the same, and a manual owner
 * survives re-analysis. Clusters that disappear are deleted, unless they
 * have a manual owner: then they are kept as retired.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS keyword_clusters (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id      BIGINT UNSIGNED NOT NULL,
            project_id        BIGINT UNSIGNED NOT NULL,
            label             VARCHAR(255) NOT NULL,
            size              INT UNSIGNED NOT NULL,
            demand            DOUBLE NOT NULL,
            members           JSON NOT NULL,
            status            VARCHAR(12) NOT NULL,
            owner_url         TEXT NULL,
            owner_source      VARCHAR(20) NULL,
            inferred_url      TEXT NULL,
            owner_reason      VARCHAR(300) NOT NULL DEFAULT "",
            candidates        JSON NOT NULL,
            overlap           JSON NOT NULL,
            overlap_flagged   INT UNSIGNED NOT NULL DEFAULT 0,
            manual_owner_url  TEXT NULL,
            manual_set_by     BIGINT UNSIGNED NULL,
            manual_set_at     DATETIME NULL,
            via               VARCHAR(120) NULL,
            revision          INT UNSIGNED NOT NULL DEFAULT 1,
            retired_at        DATETIME NULL,
            computed_at       DATETIME NOT NULL,
            created_at        DATETIME NOT NULL,
            updated_at        DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY keyword_clusters_public (public_id),
            KEY keyword_clusters_project (workspace_id, project_id, retired_at, demand),
            CONSTRAINT keyword_clusters_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT keyword_clusters_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT keyword_clusters_user_fk FOREIGN KEY (manual_set_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
