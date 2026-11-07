<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * TOTP MFA (ADR-020). The TOTP secret is encrypted with the key ring
 * (context "mfa.totp:{user_id}"); recovery codes are stored as SHA-256 hashes.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS user_mfa_methods (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id         BIGINT UNSIGNED NOT NULL,
                type            ENUM('totp') NOT NULL,
                secret          VARBINARY(512)  NOT NULL,
                last_used_step  BIGINT UNSIGNED NULL,
                confirmed_at    DATETIME        NOT NULL,
                created_at      DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY user_mfa_methods_user_type (user_id, type),
                CONSTRAINT user_mfa_methods_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS user_recovery_codes (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id    BIGINT UNSIGNED NOT NULL,
                code_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                used_at    DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY user_recovery_codes_hash (user_id, code_hash),
                CONSTRAINT user_recovery_codes_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
