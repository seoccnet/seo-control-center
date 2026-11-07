<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Content overlap review (ADR-042, 0.36.0): a person's decision on one
 * overlap candidate (keep separate, merge planned with the retained URL,
 * or undecided), keyed by the candidate's kind and members. SEOcc never
 * redirects or changes content; "merge planned" records the approval.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS overlap_reviews (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            workspace_id   BIGINT UNSIGNED NOT NULL,
            project_id     BIGINT UNSIGNED NOT NULL,
            candidate_key  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            kind           VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            members        TEXT NOT NULL,
            decision       VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            retained_url   VARCHAR(2048) NULL,
            note           VARCHAR(2000) NOT NULL DEFAULT \'\',
            version        INT UNSIGNED NOT NULL DEFAULT 1,
            decided_by     BIGINT UNSIGNED NULL,
            decided_at     DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY overlap_reviews_candidate (project_id, candidate_key),
            CONSTRAINT overlap_reviews_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT overlap_reviews_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
