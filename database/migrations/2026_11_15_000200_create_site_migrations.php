<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Site migration plans (1.2.0): the old and new origin and launch date of
 * a domain move or restructure, the old addresses with their redirect
 * suggestion, chosen target and last redirect test, and the new site's
 * addresses the suggestions are matched against. Rows are keyed by a
 * SHA-1 of their address, which forms use instead of internal IDs.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS site_migrations (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id  BIGINT UNSIGNED NOT NULL,
            project_id    BIGINT UNSIGNED NOT NULL,
            name          VARCHAR(120) NOT NULL,
            old_origin    VARCHAR(255) NOT NULL,
            new_origin    VARCHAR(255) NOT NULL,
            launch_date   DATE NULL,
            match_state   VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'idle\',
            test_state    VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'idle\',
            test_cursor   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            tested_at     DATETIME NULL,
            error         VARCHAR(500) NULL,
            created_by    BIGINT UNSIGNED NULL,
            created_at    DATETIME NOT NULL,
            updated_at    DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY site_migrations_public (public_id),
            KEY site_migrations_project (project_id),
            CONSTRAINT site_migrations_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT site_migrations_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT site_migrations_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS site_migration_urls (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_id   BIGINT UNSIGNED NOT NULL,
            url_hash       BINARY(20) NOT NULL,
            old_url        VARCHAR(2048) NOT NULL,
            old_clicks     INT UNSIGNED NULL,
            suggested_url  VARCHAR(2048) NULL,
            match_method   VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NULL,
            confidence     TINYINT UNSIGNED NULL,
            target_url     VARCHAR(2048) NULL,
            chosen_manually TINYINT(1) NOT NULL DEFAULT 0,
            test_state     VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'untested\',
            test_detail    VARCHAR(500) NULL,
            tested_at      DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY site_migration_urls_hash (migration_id, url_hash),
            KEY site_migration_urls_state (migration_id, test_state),
            CONSTRAINT site_migration_urls_migration_fk FOREIGN KEY (migration_id) REFERENCES site_migrations (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS site_migration_targets (
            migration_id   BIGINT UNSIGNED NOT NULL,
            url_hash       BINARY(20) NOT NULL,
            url            VARCHAR(2048) NOT NULL,
            PRIMARY KEY (migration_id, url_hash),
            CONSTRAINT site_migration_targets_migration_fk FOREIGN KEY (migration_id) REFERENCES site_migrations (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
