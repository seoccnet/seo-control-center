<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * OAuth sign-in for MCP clients such as ChatGPT (ADR-029): clients register
 * themselves (RFC 7591, redirect URIs limited to known AI app hosts), the
 * user approves access on a sign-in page, and the app exchanges the
 * one-time code (PKCE S256) for an ordinary MCP token plus a refresh token.
 * Codes and refresh tokens are stored as SHA-256 hashes only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS mcp_oauth_clients (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                client_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                name          VARCHAR(120)    NOT NULL,
                redirect_uris JSON            NOT NULL,
                auth_method   VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                secret_hash   BINARY(32)      NULL,
                created_ip    VARCHAR(45)     NULL,
                created_at    DATETIME        NOT NULL,
                last_used_at  DATETIME        NULL,
                PRIMARY KEY (id),
                UNIQUE KEY mcp_oauth_clients_client_id_unique (client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS mcp_oauth_codes (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code_hash      BINARY(32)      NOT NULL,
                client_id      BIGINT UNSIGNED NOT NULL,
                user_id        BIGINT UNSIGNED NOT NULL,
                workspace_id   BIGINT UNSIGNED NOT NULL,
                scopes         JSON            NOT NULL,
                redirect_uri   VARCHAR(500)    NOT NULL,
                code_challenge VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                expires_at     DATETIME        NOT NULL,
                used_at        DATETIME        NULL,
                PRIMARY KEY (id),
                UNIQUE KEY mcp_oauth_codes_hash_unique (code_hash),
                KEY mcp_oauth_codes_expires (expires_at),
                CONSTRAINT mcp_oauth_codes_client_fk FOREIGN KEY (client_id) REFERENCES mcp_oauth_clients (id) ON DELETE CASCADE,
                CONSTRAINT mcp_oauth_codes_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT mcp_oauth_codes_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS mcp_oauth_refresh_tokens (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token_hash   BINARY(32)      NOT NULL,
                client_id    BIGINT UNSIGNED NOT NULL,
                mcp_token_id BIGINT UNSIGNED NULL,
                user_id      BIGINT UNSIGNED NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                scopes       JSON            NOT NULL,
                expires_at   DATETIME        NOT NULL,
                used_at      DATETIME        NULL,
                created_at   DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY mcp_oauth_refresh_hash_unique (token_hash),
                KEY mcp_oauth_refresh_expires (expires_at),
                CONSTRAINT mcp_oauth_refresh_client_fk FOREIGN KEY (client_id) REFERENCES mcp_oauth_clients (id) ON DELETE CASCADE,
                CONSTRAINT mcp_oauth_refresh_token_fk FOREIGN KEY (mcp_token_id) REFERENCES mcp_tokens (id) ON DELETE SET NULL,
                CONSTRAINT mcp_oauth_refresh_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT mcp_oauth_refresh_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
