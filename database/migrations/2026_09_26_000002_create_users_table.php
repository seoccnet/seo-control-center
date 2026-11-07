<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS users (
                id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                email               VARCHAR(191) NOT NULL,
                name                VARCHAR(120) NOT NULL,
                password_hash       VARCHAR(255) NOT NULL,
                system_role         ENUM('none', 'admin') NOT NULL DEFAULT 'none',
                status              ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
                password_changed_at DATETIME     NOT NULL,
                last_login_at       DATETIME     NULL,
                created_at          DATETIME     NOT NULL,
                updated_at          DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY users_public_id_unique (public_id),
                UNIQUE KEY users_email_unique (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
