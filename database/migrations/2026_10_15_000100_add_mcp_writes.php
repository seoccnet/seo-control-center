<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Writable MCP (ADR-036): idempotent creates per token, an edit revision on
 * change notes (briefs and tasks already have one), and `via`, the name of
 * the AI client token that last changed a note, brief or task.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS mcp_write_requests (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token_id       BIGINT UNSIGNED NOT NULL,
            tool           VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            request_id     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            arguments_hash BINARY(32) NOT NULL,
            result         MEDIUMTEXT NULL,
            created_at     DATETIME NOT NULL,
            completed_at   DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY mcp_write_request (token_id, tool, request_id),
            KEY mcp_write_requests_created (created_at),
            CONSTRAINT mcp_write_requests_token_fk FOREIGN KEY (token_id) REFERENCES mcp_tokens (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $columns = [
            ['seo_annotations', 'revision', 'ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 1 AFTER url_hash'],
            ['seo_annotations', 'via', 'ADD COLUMN via VARCHAR(120) NULL AFTER created_by'],
            ['content_briefs', 'via', 'ADD COLUMN via VARCHAR(120) NULL AFTER created_by'],
            ['opportunity_tasks', 'via', 'ADD COLUMN via VARCHAR(120) NULL AFTER revision'],
        ];
        foreach ($columns as [$table, $column, $alter]) {
            $exists = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            );
            if ($exists === 0) {
                $db->statement('ALTER TABLE ' . $table . ' ' . $alter);
            }
        }
    }
};
