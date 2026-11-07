<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Measurement plans (ADR-042, 0.39.0): what a recorded change should move
 * and how it will be judged. One plan per SEO change (annotation): the
 * objective, the primary metric, target pages and queries, control pages,
 * the comparison period, review dates, the project timezone, and a frozen
 * baseline with its source completeness. Versioned for concurrent edits.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS measurement_plans (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id   BIGINT UNSIGNED NOT NULL,
            project_id     BIGINT UNSIGNED NOT NULL,
            annotation_id  BIGINT UNSIGNED NOT NULL,
            objective      VARCHAR(1000) NOT NULL,
            metric         VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            target_pages   TEXT NOT NULL,
            target_queries TEXT NOT NULL,
            control_pages  TEXT NOT NULL,
            days           SMALLINT UNSIGNED NOT NULL,
            review_dates   VARCHAR(500) NOT NULL,
            timezone       VARCHAR(64) NOT NULL,
            baseline       MEDIUMTEXT NULL,
            frozen_at      DATETIME NULL,
            version        INT UNSIGNED NOT NULL DEFAULT 1,
            updated_by     BIGINT UNSIGNED NULL,
            created_at     DATETIME NOT NULL,
            updated_at     DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY measurement_plans_public (public_id),
            UNIQUE KEY measurement_plans_annotation (annotation_id),
            KEY measurement_plans_project (project_id),
            CONSTRAINT measurement_plans_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT measurement_plans_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT measurement_plans_annotation_fk FOREIGN KEY (annotation_id) REFERENCES seo_annotations (id) ON DELETE CASCADE,
            CONSTRAINT measurement_plans_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
