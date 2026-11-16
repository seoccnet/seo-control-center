<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Competitor content watch (1.6.0). `competitor_watch` holds the opt-in per
 * project. `competitor_sitemaps` is the state of each competitor's latest
 * daily sitemap read. `competitor_pages` lists every page URL a competitor's
 * sitemap named: `first_seen` is NULL for pages present at the first read
 * (the baseline), `removed_on` is set when a later complete read no longer
 * lists the page.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS competitor_watch (
                project_id   BIGINT UNSIGNED NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                enabled      TINYINT(1)      NOT NULL DEFAULT 0,
                updated_at   DATETIME        NOT NULL,
                PRIMARY KEY (project_id),
                KEY competitor_watch_enabled (enabled),
                CONSTRAINT competitor_watch_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT competitor_watch_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS competitor_sitemaps (
                competitor_id BIGINT UNSIGNED  NOT NULL,
                project_id    BIGINT UNSIGNED  NOT NULL,
                checked_on    DATE             NOT NULL,
                baseline_on   DATE             NULL,
                urls          INT UNSIGNED     NULL,
                capped        TINYINT(1)       NOT NULL DEFAULT 0,
                error         VARCHAR(255)     NULL,
                PRIMARY KEY (competitor_id),
                KEY competitor_sitemaps_project (project_id),
                CONSTRAINT competitor_sitemaps_competitor_fk FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE CASCADE,
                CONSTRAINT competitor_sitemaps_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS competitor_pages (
                competitor_id BIGINT UNSIGNED NOT NULL,
                url_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                project_id    BIGINT UNSIGNED NOT NULL,
                url           VARCHAR(2048)   NOT NULL,
                first_seen    DATE            NULL,
                removed_on    DATE            NULL,
                PRIMARY KEY (competitor_id, url_hash),
                KEY competitor_pages_first_seen (project_id, first_seen),
                KEY competitor_pages_removed (project_id, removed_on),
                CONSTRAINT competitor_pages_competitor_fk FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE CASCADE,
                CONSTRAINT competitor_pages_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
