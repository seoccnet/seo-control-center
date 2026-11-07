<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Database-backed queue and scheduler (QUEUE_AND_CRON.md, ADR-002, ADR-015)
 * plus a small key/value table for installation-level state (heartbeats, IDs).
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS jobs (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                queue             VARCHAR(32)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                type              VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                payload           JSON         NOT NULL,
                priority          SMALLINT     NOT NULL DEFAULT 0,
                status            ENUM('queued', 'running', 'completed', 'failed') NOT NULL DEFAULT 'queued',
                attempts          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts      SMALLINT UNSIGNED NOT NULL DEFAULT 3,
                available_at      DATETIME     NOT NULL,
                reservation_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
                reserved_until    DATETIME     NULL,
                started_at        DATETIME     NULL,
                finished_at       DATETIME     NULL,
                last_error        VARCHAR(1000) NULL,
                idempotency_key   VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
                workspace_id      BIGINT UNSIGNED NULL,
                created_at        DATETIME     NOT NULL,
                updated_at        DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY jobs_public_id_unique (public_id),
                UNIQUE KEY jobs_idempotency_key_unique (idempotency_key),
                KEY jobs_claim (status, queue, available_at, priority),
                KEY jobs_reserved (status, reserved_until),
                KEY jobs_finished (status, finished_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS schedule_runs (
                task_name  VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                slot_at    DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (task_name, slot_at),
                KEY schedule_runs_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS system_state (
                name       VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                value      JSON     NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        // Unique, random installation identifier used for license activation (LICENSING.md).
        $db->execute(
            'INSERT INTO system_state (name, value, updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name = VALUES(name)',
            ['installation_id', json_encode(bin2hex(random_bytes(16)), JSON_THROW_ON_ERROR)],
        );
    }
};
