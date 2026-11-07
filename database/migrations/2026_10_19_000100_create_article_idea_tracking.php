<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Published articles from article ideas (ADR-041, G3): one per project and
 * idea, with a snapshot of the idea's searches and prediction when it was
 * published, and the weekly Search Console results of its page since.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS article_idea_tracking (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id  BIGINT UNSIGNED NOT NULL,
            project_id    BIGINT UNSIGNED NOT NULL,
            idea_key      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            main_keyword  VARCHAR(255) NOT NULL,
            keywords      TEXT NOT NULL,
            kind          VARCHAR(16) NOT NULL,
            level         VARCHAR(16) NOT NULL,
            prediction    TEXT NOT NULL,
            url           VARCHAR(2048) NOT NULL,
            published_on  DATE NOT NULL,
            source        VARCHAR(16) NOT NULL,
            result        MEDIUMTEXT NULL,
            checked_at    DATETIME NULL,
            created_by    BIGINT UNSIGNED NULL,
            created_at    DATETIME NOT NULL,
            updated_at    DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY article_idea_tracking_public (public_id),
            UNIQUE KEY article_idea_tracking_idea (project_id, idea_key),
            KEY article_idea_tracking_checked (checked_at),
            CONSTRAINT article_idea_tracking_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT article_idea_tracking_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
