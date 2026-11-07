<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Cached signed license entitlement (LICENSING.md, ADR-008).
 * Single row (id = 1). The license key is encrypted with the key ring.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS license_state (
                id              TINYINT UNSIGNED NOT NULL,
                license_key     VARBINARY(512)   NULL,
                payload         TEXT             NULL,
                signature       VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
                last_checked_at DATETIME         NULL,
                last_success_at DATETIME         NULL,
                last_error      VARCHAR(500)     NULL,
                updated_at      DATETIME         NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
