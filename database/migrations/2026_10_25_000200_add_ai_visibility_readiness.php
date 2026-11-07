<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * AI visibility readiness (0.43.0):
 * - ai_crawler_checks: the latest robots.txt and llms.txt check of each
 *   project, with what robots.txt allows each AI crawler;
 * - site_audit_pages.citation_signals: what a crawled page offers to quote
 *   (question headings, tables, lists, figures, lead length, updated date);
 *   pages crawled before stay NULL: unknown, never "missing";
 * - ga4_ai_referrals and ga4_ai_referral_imports: GA4 sessions referred by
 *   AI assistants, per day and source, and the latest import window.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS ai_crawler_checks (
            project_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            workspace_id BIGINT UNSIGNED NOT NULL,
            robots_url VARCHAR(2048) NOT NULL,
            robots_state VARCHAR(20) NOT NULL,
            robots_status SMALLINT UNSIGNED NULL,
            agents TEXT NOT NULL,
            llms_state VARCHAR(20) NOT NULL,
            llms_status SMALLINT UNSIGNED NULL,
            llms_bytes INT UNSIGNED NULL,
            checked_at DATETIME NOT NULL,
            KEY ai_crawler_checked (checked_at),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['site_audit_pages', 'citation_signals']);
        if ($exists === 0) {
            $db->statement('ALTER TABLE site_audit_pages ADD COLUMN citation_signals VARCHAR(500) NULL');
        }

        $db->statement('CREATE TABLE IF NOT EXISTS ga4_ai_referrals (
            project_id BIGINT UNSIGNED NOT NULL,
            date DATE NOT NULL,
            source VARCHAR(190) NOT NULL,
            sessions INT UNSIGNED NOT NULL,
            key_events DECIMAL(20,6) NOT NULL,
            revenue DECIMAL(20,6) NOT NULL,
            PRIMARY KEY (project_id, date, source),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS ga4_ai_referral_imports (
            project_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            period_from DATE NULL,
            period_to DATE NULL,
            currency VARCHAR(7) NULL,
            limited TINYINT(1) NOT NULL DEFAULT 0,
            fetched_at DATETIME NULL,
            error VARCHAR(500) NULL,
            error_at DATETIME NULL,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
