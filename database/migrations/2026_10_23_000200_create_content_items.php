<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Content inventory (ADR-042, 0.33.0): CMS records entered by hand or from a
 * CSV export (SEOcc has no CMS connection). Pages from crawls, sitemaps,
 * Search Console, briefs and plans stay in their own tables and are joined
 * when the inventory is read.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS content_items (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id  BIGINT UNSIGNED NOT NULL,
            project_id    BIGINT UNSIGNED NOT NULL,
            title         VARCHAR(300) NOT NULL,
            url           VARCHAR(2048) NULL,
            url_key_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            status        VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            publish_on    DATE NULL,
            cms_id        VARCHAR(100) NULL,
            source        VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            note          VARCHAR(500) NOT NULL DEFAULT \'\',
            linked_url    VARCHAR(2048) NULL,
            version       INT UNSIGNED NOT NULL DEFAULT 1,
            created_by    BIGINT UNSIGNED NULL,
            created_at    DATETIME NOT NULL,
            updated_at    DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY content_items_public (public_id),
            KEY content_items_project (project_id, status),
            KEY content_items_url (project_id, url_key_hash),
            KEY content_items_cms (project_id, cms_id),
            CONSTRAINT content_items_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT content_items_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
