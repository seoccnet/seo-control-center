<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Results-page change events (ADR-039, E1), derived from two comparable
 * rank checks of a tracked keyword: features, competitors, new URLs, the
 * project's own movement and the dominant page type. One event per
 * keyword, device, day, kind and subject; kept 400 days.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS serp_events (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            workspace_id       BIGINT UNSIGNED NOT NULL,
            project_id         BIGINT UNSIGNED NOT NULL,
            project_keyword_id BIGINT UNSIGNED NOT NULL,
            device             VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            checked_on         DATE NOT NULL,
            previous_on        DATE NOT NULL,
            kind               VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            subject            VARCHAR(2048) NOT NULL,
            subject_hash       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            detail             TEXT NULL,
            created_at         DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY serp_events_once (project_keyword_id, device, checked_on, kind, subject_hash),
            KEY serp_events_project_day (project_id, device, checked_on),
            KEY serp_events_project_kind (project_id, kind, checked_on),
            CONSTRAINT serp_events_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT serp_events_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT serp_events_keyword_fk FOREIGN KEY (project_keyword_id) REFERENCES project_keywords (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
