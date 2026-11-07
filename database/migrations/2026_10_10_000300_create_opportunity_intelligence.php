<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Opportunity intelligence (ADR-034):
 * - project_business_profiles: what the business sells and wants from
 *   search, the input of the business-potential score, and the project's
 *   difficulty and competitor-strength limits;
 * - opportunity_keyword_ratings: business potential (0-3) the team set for
 *   single keywords, overriding the rules;
 * - keyword_serps: the latest results page of each tracked keyword and
 *   device (top 10 with titles, feature types, AI Overview flag), one row
 *   per keyword, overwritten by each rank check;
 * - research kinds: keyword suggestions, result-domain strength and AI seed
 *   suggestions.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS project_business_profiles (
                project_id      BIGINT UNSIGNED NOT NULL,
                business_model  VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                goal            VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                description     TEXT            NOT NULL,
                audience        VARCHAR(300)    NOT NULL,
                offerings       TEXT            NOT NULL,
                exclusions      TEXT            NOT NULL,
                max_difficulty  TINYINT UNSIGNED NULL,
                max_strength    TINYINT UNSIGNED NULL,
                updated_by      BIGINT UNSIGNED NULL,
                updated_at      DATETIME        NOT NULL,
                PRIMARY KEY (project_id),
                CONSTRAINT business_profiles_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT business_profiles_user_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS opportunity_keyword_ratings (
                project_id   BIGINT UNSIGNED NOT NULL,
                keyword_hash BINARY(32)      NOT NULL,
                keyword      VARCHAR(255)    NOT NULL,
                score        TINYINT UNSIGNED NOT NULL,
                updated_by   BIGINT UNSIGNED NULL,
                updated_at   DATETIME        NOT NULL,
                PRIMARY KEY (project_id, keyword_hash),
                CONSTRAINT keyword_ratings_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT keyword_ratings_user_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $db->statement(
            "CREATE TABLE IF NOT EXISTS keyword_serps (
                project_keyword_id BIGINT UNSIGNED NOT NULL,
                device             ENUM('desktop', 'mobile') NOT NULL,
                project_id         BIGINT UNSIGNED NOT NULL,
                checked_on         DATE            NOT NULL,
                results            TEXT            NOT NULL,
                features           VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NULL,
                ai_overview        TINYINT(1)      NOT NULL DEFAULT 0,
                updated_at         DATETIME        NOT NULL,
                PRIMARY KEY (project_keyword_id, device),
                KEY keyword_serps_project (project_id, device),
                CONSTRAINT keyword_serps_keyword_fk FOREIGN KEY (project_keyword_id) REFERENCES project_keywords (id) ON DELETE CASCADE,
                CONSTRAINT keyword_serps_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
        $db->statement("ALTER TABLE research_results MODIFY kind ENUM('ranked', 'gap', 'ideas', 'competitors', 'overview', 'llm', 'llm_prompts', 'bl_profiles', 'bl_domains', 'bl_new', 'bl_lost', 'bl_anchors', 'bl_gap', 'bl_links', 'bl_lost_links', 'rendered_audit', 'llm_answers', 'suggestions', 'serp_strength', 'seed_ideas') NOT NULL");
    }
};
