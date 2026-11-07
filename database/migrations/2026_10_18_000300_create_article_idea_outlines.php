<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Competitor outlines of article ideas (ADR-041, G1 part 2): the structure
 * of the idea's top competitor articles (and your page for an update),
 * read on request with the free page reader. Only headings, question
 * headings and word counts are stored, never the text. One row per idea.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS article_idea_outlines (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id   BIGINT UNSIGNED NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            idea_key     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            status       VARCHAR(16) NOT NULL,
            pages        MEDIUMTEXT NULL,
            own_page     MEDIUMTEXT NULL,
            error        VARCHAR(500) NULL,
            requested_by BIGINT UNSIGNED NULL,
            requested_at DATETIME NOT NULL,
            fetched_at   DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY article_idea_outlines_idea (project_id, idea_key),
            CONSTRAINT article_idea_outlines_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT article_idea_outlines_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
