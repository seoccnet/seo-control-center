<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Invitations to a workspace (1.0): a one-time link, shown once to the
 * inviter, valid for 7 days. Only the SHA-256 of the token is stored.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS workspace_invitations (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                email        VARCHAR(254)    NOT NULL,
                role_key     VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                token_hash   BINARY(32)      NOT NULL,
                invited_by   BIGINT UNSIGNED NULL,
                created_at   DATETIME        NOT NULL,
                expires_at   DATETIME        NOT NULL,
                accepted_at  DATETIME        NULL,
                revoked_at   DATETIME        NULL,
                PRIMARY KEY (id),
                UNIQUE KEY workspace_invitations_public_id (public_id),
                UNIQUE KEY workspace_invitations_token (token_hash),
                KEY workspace_invitations_workspace (workspace_id, accepted_at, revoked_at),
                CONSTRAINT workspace_invitations_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT workspace_invitations_inviter_fk FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
