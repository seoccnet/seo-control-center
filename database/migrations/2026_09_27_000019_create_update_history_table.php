<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Updater (0.8): one row per installation attempt of a release package.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS update_history (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                from_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                to_version   VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status       ENUM('completed', 'failed') NOT NULL,
                message      VARCHAR(1000)   NULL,
                installed_by BIGINT UNSIGNED NULL,
                started_at   DATETIME        NOT NULL,
                finished_at  DATETIME        NOT NULL,
                PRIMARY KEY (id),
                KEY update_history_finished (finished_at),
                CONSTRAINT update_history_user_fk FOREIGN KEY (installed_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
