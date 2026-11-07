<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * E-mail (ADR-027): the installation's mail settings (one row; the SMTP
 * password is encrypted with the key ring) and password-reset tokens (only
 * the SHA-256 hash is stored).
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS mail_settings (
                id            TINYINT UNSIGNED NOT NULL,
                transport     ENUM('none', 'smtp', 'php') NOT NULL DEFAULT 'none',
                host          VARCHAR(255)    NULL,
                port          SMALLINT UNSIGNED NULL,
                encryption    ENUM('tls', 'ssl', 'none') NOT NULL DEFAULT 'tls',
                username      VARCHAR(255)    NULL,
                password      VARBINARY(1024) NULL,
                from_address  VARCHAR(191)    NULL,
                from_name     VARCHAR(120)    NULL,
                updated_by    BIGINT UNSIGNED NULL,
                updated_at    DATETIME        NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT mail_settings_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS password_resets (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id     BIGINT UNSIGNED NOT NULL,
                token_hash  BINARY(32)      NOT NULL,
                created_at  DATETIME        NOT NULL,
                expires_at  DATETIME        NOT NULL,
                used_at     DATETIME        NULL,
                ip          VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NULL,
                PRIMARY KEY (id),
                UNIQUE KEY password_resets_token (token_hash),
                KEY password_resets_user (user_id, used_at),
                CONSTRAINT password_resets_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
