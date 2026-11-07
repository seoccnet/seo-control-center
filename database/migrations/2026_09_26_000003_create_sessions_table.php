<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Server-side sessions. The cookie holds a random token; only its SHA-256
 * hash is stored, so a database leak does not expose usable session tokens.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS sessions (
                id               CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                user_id          BIGINT UNSIGNED NULL,
                data             MEDIUMTEXT      NOT NULL,
                ip_address       VARCHAR(45)     NULL,
                user_agent       VARCHAR(255)    NULL,
                created_at       DATETIME        NOT NULL,
                last_activity_at DATETIME        NOT NULL,
                expires_at       DATETIME        NOT NULL,
                PRIMARY KEY (id),
                KEY sessions_user_id (user_id),
                KEY sessions_expires_at (expires_at),
                CONSTRAINT sessions_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
