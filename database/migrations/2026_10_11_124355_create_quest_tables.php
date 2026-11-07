<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Quest Log progress (ADR-035). Quest definitions are code; only per user
 * per workspace progress, earned ranks and preferences are stored. Rows are
 * never exposed by id, so there is no public_id.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS quest_profiles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                hidden TINYINT(1) NOT NULL DEFAULT 0,
                seen_quests JSON NULL,
                checked_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY quest_profiles_user_workspace (user_id, workspace_id),
                KEY quest_profiles_checked (checked_at),
                CONSTRAINT quest_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT quest_profiles_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS quest_progress (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                quest_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                step_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                completed_at DATETIME NULL,
                skipped_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY quest_progress_step (user_id, workspace_id, quest_key, step_key),
                CONSTRAINT quest_progress_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT quest_progress_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS quest_ranks (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                workspace_id BIGINT UNSIGNED NOT NULL,
                chapter TINYINT UNSIGNED NOT NULL,
                earned_at DATETIME NOT NULL,
                announced_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY quest_ranks_chapter (user_id, workspace_id, chapter),
                CONSTRAINT quest_ranks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT quest_ranks_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
