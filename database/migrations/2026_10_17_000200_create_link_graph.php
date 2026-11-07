<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Internal link graph with context (ADR-040, F1). Every crawled internal
 * link gets its anchor text and its place on the page (body, nav, header,
 * footer, aside). Body links also keep the surrounding paragraph, and each
 * crawled page keeps up to 25 body paragraphs for link suggestions. Those
 * two text tables and the suggestions are kept only for a project's newest
 * finished audit and an audit still running.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        foreach ([
            ['anchor', 'ADD COLUMN anchor VARCHAR(160) NULL'],
            ['place', 'ADD COLUMN place VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL'],
        ] as [$column, $alter]) {
            $exists = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_audit_links' AND COLUMN_NAME = ?",
                [$column],
            );
            if ($exists === 0) {
                $db->statement('ALTER TABLE site_audit_links ' . $alter);
            }
        }

        $db->statement('CREATE TABLE IF NOT EXISTS site_audit_link_contexts (
            audit_id     BIGINT UNSIGNED NOT NULL,
            from_page_id BIGINT UNSIGNED NOT NULL,
            to_page_id   BIGINT UNSIGNED NOT NULL,
            context      VARCHAR(400) NOT NULL,
            PRIMARY KEY (audit_id, from_page_id, to_page_id),
            KEY site_audit_link_contexts_to (audit_id, to_page_id),
            CONSTRAINT site_audit_link_contexts_audit_fk FOREIGN KEY (audit_id) REFERENCES site_audits (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS site_audit_paragraphs (
            audit_id BIGINT UNSIGNED NOT NULL,
            page_id  BIGINT UNSIGNED NOT NULL,
            position TINYINT UNSIGNED NOT NULL,
            text     VARCHAR(400) NOT NULL,
            PRIMARY KEY (audit_id, page_id, position),
            CONSTRAINT site_audit_paragraphs_audit_fk FOREIGN KEY (audit_id) REFERENCES site_audits (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS link_suggestions (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            audit_id       BIGINT UNSIGNED NOT NULL,
            project_id     BIGINT UNSIGNED NOT NULL,
            source_page_id BIGINT UNSIGNED NOT NULL,
            target_page_id BIGINT UNSIGNED NOT NULL,
            cluster_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
            topic          VARCHAR(255) NOT NULL,
            anchor         VARCHAR(160) NOT NULL,
            paragraph      VARCHAR(400) NOT NULL,
            score          DOUBLE NOT NULL,
            created_at     DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY link_suggestions_pair (audit_id, source_page_id, target_page_id),
            KEY link_suggestions_project (project_id, audit_id, score),
            KEY link_suggestions_target (audit_id, target_page_id),
            CONSTRAINT link_suggestions_audit_fk FOREIGN KEY (audit_id) REFERENCES site_audits (id) ON DELETE CASCADE,
            CONSTRAINT link_suggestions_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
