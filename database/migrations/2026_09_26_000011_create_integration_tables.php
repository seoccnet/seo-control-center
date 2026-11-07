<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Integrations (INTEGRATIONS.md): installation-level OAuth client settings,
 * workspace-owned connections with encrypted tokens, discovered Search
 * Console properties and the project ↔ property link.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        // Each installation uses its own OAuth client (self-hosted); the secret is encrypted.
        $db->statement(
            "CREATE TABLE IF NOT EXISTS oauth_clients (
                provider      ENUM('google') NOT NULL,
                client_id     VARCHAR(255)   NOT NULL,
                client_secret VARBINARY(512) NOT NULL,
                updated_by    BIGINT UNSIGNED NULL,
                updated_at    DATETIME       NOT NULL,
                PRIMARY KEY (provider)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS connections (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                workspace_id  BIGINT UNSIGNED NOT NULL,
                provider      ENUM('google') NOT NULL,
                account_email VARCHAR(191)   NOT NULL,
                scopes        VARCHAR(500)   NOT NULL,
                status        ENUM('active', 'error', 'revoked') NOT NULL DEFAULT 'active',
                last_error    VARCHAR(500)   NULL,
                created_by    BIGINT UNSIGNED NULL,
                created_at    DATETIME       NOT NULL,
                updated_at    DATETIME       NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY connections_public_id_unique (public_id),
                UNIQUE KEY connections_workspace_account (workspace_id, provider, account_email),
                CONSTRAINT connections_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT connections_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS connection_tokens (
                connection_id    BIGINT UNSIGNED NOT NULL,
                refresh_token    VARBINARY(1024) NULL,
                access_token     VARBINARY(4096) NULL,
                access_expires_at DATETIME       NULL,
                updated_at       DATETIME        NOT NULL,
                PRIMARY KEY (connection_id),
                CONSTRAINT connection_tokens_connection_fk FOREIGN KEY (connection_id) REFERENCES connections (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS google_properties (
                id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                connection_id    BIGINT UNSIGNED NOT NULL,
                site_url         VARCHAR(255)    NOT NULL,
                permission_level VARCHAR(32)     NOT NULL,
                discovered_at    DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY google_properties_connection_site (connection_id, site_url),
                CONSTRAINT google_properties_connection_fk FOREIGN KEY (connection_id) REFERENCES connections (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        // One Search Console property per project. Imported data is kept if the link changes.
        $db->statement(
            'CREATE TABLE IF NOT EXISTS project_search_console (
                project_id    BIGINT UNSIGNED NOT NULL,
                connection_id BIGINT UNSIGNED NOT NULL,
                site_url      VARCHAR(255)    NOT NULL,
                linked_by     BIGINT UNSIGNED NULL,
                linked_at     DATETIME        NOT NULL,
                PRIMARY KEY (project_id),
                KEY project_search_console_connection (connection_id),
                CONSTRAINT project_search_console_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT project_search_console_connection_fk FOREIGN KEY (connection_id) REFERENCES connections (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
