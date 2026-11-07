<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Change bundles (ADR-042, 0.40.0): a reviewed set of exact edits to one
 * page with its problem, evidence, expected effects and validation, a live
 * baseline, an approval bound to a content hash, the version guard's live
 * checks, a manual deployment record (with partial failures), verification
 * and rollback. SEOcc never edits the live site. `data` holds the bundle's
 * reviewed content and state as bounded JSON; every step is also logged in
 * change_bundle_events.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS change_bundles (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            project_id   BIGINT UNSIGNED NOT NULL,
            title        VARCHAR(200) NOT NULL,
            url          VARCHAR(2048) NOT NULL,
            status       VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            data         MEDIUMTEXT NOT NULL,
            version      INT UNSIGNED NOT NULL DEFAULT 1,
            created_by   BIGINT UNSIGNED NULL,
            created_at   DATETIME NOT NULL,
            updated_at   DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY change_bundles_public (public_id),
            KEY change_bundles_project (project_id, updated_at),
            CONSTRAINT change_bundles_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT change_bundles_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT change_bundles_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS change_bundle_events (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bundle_id  BIGINT UNSIGNED NOT NULL,
            kind       VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            detail     VARCHAR(2000) NOT NULL,
            user_id    BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY change_bundle_events_bundle (bundle_id, id),
            CONSTRAINT change_bundle_events_bundle_fk FOREIGN KEY (bundle_id) REFERENCES change_bundles (id) ON DELETE CASCADE,
            CONSTRAINT change_bundle_events_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
