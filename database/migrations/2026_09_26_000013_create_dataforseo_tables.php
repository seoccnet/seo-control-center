<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * DataForSEO (0.3, part 1): per-workspace API credentials, provider budgets,
 * the usage/cost ledger and short-lived raw provider responses.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        // One DataForSEO account per workspace; the API password is encrypted.
        $db->statement(
            "CREATE TABLE IF NOT EXISTS dataforseo_profiles (
                workspace_id BIGINT UNSIGNED NOT NULL,
                login        VARCHAR(191)    NOT NULL,
                password     VARBINARY(512)  NOT NULL,
                status       ENUM('active', 'error') NOT NULL DEFAULT 'active',
                last_error   VARCHAR(500)    NULL,
                balance      DECIMAL(14,4)   NULL,
                checked_at   DATETIME        NULL,
                created_by   BIGINT UNSIGNED NULL,
                created_at   DATETIME        NOT NULL,
                updated_at   DATETIME        NOT NULL,
                PRIMARY KEY (workspace_id),
                CONSTRAINT dataforseo_profiles_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT dataforseo_profiles_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        // Spending limits per workspace and provider, in USD. NULL = no limit.
        // The row is also the lock that serializes budget reservations.
        $db->statement(
            'CREATE TABLE IF NOT EXISTS api_budgets (
                workspace_id    BIGINT UNSIGNED NOT NULL,
                provider        VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                daily_limit     DECIMAL(12,4)   NULL,
                monthly_limit   DECIMAL(12,4)   NULL,
                operation_limit DECIMAL(12,4)   NULL,
                updated_by      BIGINT UNSIGNED NULL,
                updated_at      DATETIME        NOT NULL,
                PRIMARY KEY (workspace_id, provider),
                CONSTRAINT api_budgets_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT api_budgets_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        // Cost ledger: a row is reserved with the estimated cost before a paid
        // request and settled with the provider-reported cost afterwards.
        $db->statement(
            "CREATE TABLE IF NOT EXISTS api_usage (
                id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                workspace_id     BIGINT UNSIGNED NOT NULL,
                project_id       BIGINT UNSIGNED NULL,
                provider         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                operation        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status           ENUM('reserved', 'charged', 'released') NOT NULL,
                estimated_cost   DECIMAL(12,6)   NOT NULL,
                cost             DECIMAL(12,6)   NULL,
                provider_task_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                created_at       DATETIME        NOT NULL,
                settled_at       DATETIME        NULL,
                PRIMARY KEY (id),
                KEY api_usage_workspace_provider_created (workspace_id, provider, created_at),
                KEY api_usage_status_created (status, created_at),
                CONSTRAINT api_usage_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT api_usage_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        // Raw responses for diagnostics/reprocessing, gzip-compressed, deleted after expires_at.
        $db->statement(
            'CREATE TABLE IF NOT EXISTS provider_responses (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                workspace_id BIGINT UNSIGNED NOT NULL,
                api_usage_id BIGINT UNSIGNED NULL,
                provider     VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                operation    VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                http_status  SMALLINT UNSIGNED NOT NULL,
                body         MEDIUMBLOB      NOT NULL,
                created_at   DATETIME        NOT NULL,
                expires_at   DATETIME        NOT NULL,
                PRIMARY KEY (id),
                KEY provider_responses_expires (expires_at),
                KEY provider_responses_usage (api_usage_id),
                CONSTRAINT provider_responses_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
