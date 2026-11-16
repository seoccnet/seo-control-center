<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Read-only client links (1.3.0): a secret link to a project's report that
 * works without an account until it expires or is revoked. Only the
 * SHA-256 of the link's token is stored, like session tokens (ADR-019), so
 * a database copy cannot be turned into working links.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS report_share_links (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id   BIGINT UNSIGNED NOT NULL,
            project_id     BIGINT UNSIGNED NOT NULL,
            token_hash     BINARY(32) NOT NULL,
            token_hint     CHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            label          VARCHAR(120) NOT NULL,
            created_by     BIGINT UNSIGNED NULL,
            expires_at     DATETIME NOT NULL,
            revoked_at     DATETIME NULL,
            views          INT UNSIGNED NOT NULL DEFAULT 0,
            last_viewed_at DATETIME NULL,
            created_at     DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY report_share_links_public (public_id),
            UNIQUE KEY report_share_links_token (token_hash),
            KEY report_share_links_project (project_id, created_at),
            CONSTRAINT report_share_links_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT report_share_links_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT report_share_links_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
