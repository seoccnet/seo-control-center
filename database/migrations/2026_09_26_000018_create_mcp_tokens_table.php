<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * MCP (0.7): access tokens for AI clients. Only the SHA-256 of the token
 * is stored; the plaintext is shown once at creation (MCP.md -> Tokens).
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS mcp_tokens (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                workspace_id  BIGINT UNSIGNED NOT NULL,
                created_by    BIGINT UNSIGNED NOT NULL,
                name          VARCHAR(120)    NOT NULL,
                token_hash    BINARY(32)      NOT NULL,
                token_prefix  VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                scopes        VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                project_ids   TEXT            NULL,
                rate_limit    SMALLINT UNSIGNED NOT NULL,
                expires_at    DATETIME        NULL,
                revoked_at    DATETIME        NULL,
                last_used_at  DATETIME        NULL,
                created_at    DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY mcp_tokens_public_id_unique (public_id),
                UNIQUE KEY mcp_tokens_hash_unique (token_hash),
                KEY mcp_tokens_workspace (workspace_id, created_at),
                CONSTRAINT mcp_tokens_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT mcp_tokens_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
