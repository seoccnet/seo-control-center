<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Indexing history (ADR-042, 0.37.0): every successful Google URL Inspection
 * result (bounded per URL), and indexing requests a person made in Search
 * Console and recorded here with evidence. SEOcc never submits indexing
 * requests itself; a recorded request is never treated as "indexed".
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS google_inspection_history (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id       BIGINT UNSIGNED NOT NULL,
            binding          BINARY(32) NOT NULL,
            url_hash         BINARY(32) NOT NULL,
            checked_at       DATETIME NOT NULL,
            verdict          VARCHAR(16) NOT NULL,
            coverage         VARCHAR(300) NOT NULL,
            indexing_state   VARCHAR(60) NOT NULL,
            robots           VARCHAR(40) NOT NULL,
            last_crawl       DATETIME NULL,
            google_canonical VARCHAR(2048) NOT NULL,
            user_canonical   VARCHAR(2048) NOT NULL,
            PRIMARY KEY (id),
            KEY google_inspection_history_url (project_id, binding, url_hash, checked_at),
            CONSTRAINT google_inspection_history_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS indexing_requests (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            project_id   BIGINT UNSIGNED NOT NULL,
            url_hash     BINARY(32) NOT NULL,
            url          VARCHAR(2048) NOT NULL,
            channel      VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            requested_on DATE NOT NULL,
            evidence     VARCHAR(1000) NOT NULL,
            note         VARCHAR(2000) NOT NULL DEFAULT \'\',
            created_by   BIGINT UNSIGNED NULL,
            created_at   DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY indexing_requests_public (public_id),
            KEY indexing_requests_url (project_id, url_hash, requested_on),
            CONSTRAINT indexing_requests_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT indexing_requests_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT indexing_requests_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
