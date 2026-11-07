<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Claim verification (ADR-042, 0.34.0): the factual claims of a content
 * brief with their source, the scope the claim and the source each cover,
 * a person's verdict and every earlier revision. Automated flags are
 * computed when read and never stored as a verdict.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS brief_claims (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id    BIGINT UNSIGNED NOT NULL,
            project_id      BIGINT UNSIGNED NOT NULL,
            brief_public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            claim           VARCHAR(1000) NOT NULL,
            subject         VARCHAR(300) NOT NULL DEFAULT \'\',
            claim_scope     TEXT NOT NULL,
            source_url      VARCHAR(2048) NULL,
            publisher       VARCHAR(200) NOT NULL DEFAULT \'\',
            checked_on      DATE NULL,
            passage         VARCHAR(2000) NOT NULL DEFAULT \'\',
            source_scope    TEXT NOT NULL,
            status          VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            note            VARCHAR(2000) NOT NULL DEFAULT \'\',
            reviewed_by     BIGINT UNSIGNED NULL,
            reviewed_at     DATETIME NULL,
            version         INT UNSIGNED NOT NULL DEFAULT 1,
            created_by      BIGINT UNSIGNED NULL,
            created_at      DATETIME NOT NULL,
            updated_at      DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY brief_claims_public (public_id),
            KEY brief_claims_brief (project_id, brief_public_id, id),
            KEY brief_claims_status (project_id, status),
            CONSTRAINT brief_claims_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT brief_claims_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS brief_claim_revisions (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            claim_id    BIGINT UNSIGNED NOT NULL,
            version     INT UNSIGNED NOT NULL,
            snapshot    TEXT NOT NULL,
            changed_by  BIGINT UNSIGNED NULL,
            changed_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY brief_claim_revisions_version (claim_id, version),
            CONSTRAINT brief_claim_revisions_claim_fk FOREIGN KEY (claim_id) REFERENCES brief_claims (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
