<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * AI assistant (ADR-028): the workspace's Claude API key (encrypted with the
 * key ring) and model, and the conversations of each user. A conversation
 * keeps the full Claude message history (text, tool calls and tool
 * results) so the chat continues across pages; `pending` holds a write the
 * user still has to confirm, `model_calls` counts the calls since the last
 * user message.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            "CREATE TABLE IF NOT EXISTS assistant_settings (
                workspace_id BIGINT UNSIGNED NOT NULL,
                api_key      VARBINARY(1024) NOT NULL,
                model        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status       ENUM('active', 'error') NOT NULL DEFAULT 'active',
                last_error   VARCHAR(500)    NULL,
                created_by   BIGINT UNSIGNED NULL,
                created_at   DATETIME        NOT NULL,
                updated_at   DATETIME        NOT NULL,
                PRIMARY KEY (workspace_id),
                CONSTRAINT assistant_settings_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT assistant_settings_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS assistant_conversations (
                id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
                public_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                workspace_id BIGINT UNSIGNED  NOT NULL,
                user_id      BIGINT UNSIGNED  NOT NULL,
                pending      JSON             NULL,
                model_calls  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at   DATETIME         NOT NULL,
                updated_at   DATETIME         NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY assistant_conversations_public_id_unique (public_id),
                KEY assistant_conversations_user (workspace_id, user_id, updated_at),
                CONSTRAINT assistant_conversations_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
                CONSTRAINT assistant_conversations_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->statement(
            "CREATE TABLE IF NOT EXISTS assistant_messages (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id BIGINT UNSIGNED NOT NULL,
                role            ENUM('user', 'assistant') NOT NULL,
                content         MEDIUMTEXT      NOT NULL,
                input_tokens    INT UNSIGNED    NULL,
                output_tokens   INT UNSIGNED    NULL,
                created_at      DATETIME        NOT NULL,
                PRIMARY KEY (id),
                KEY assistant_messages_conversation (conversation_id, id),
                CONSTRAINT assistant_messages_conversation_fk FOREIGN KEY (conversation_id) REFERENCES assistant_conversations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }
};
