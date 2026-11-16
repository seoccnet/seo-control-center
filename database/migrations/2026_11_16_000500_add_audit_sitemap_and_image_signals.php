<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * Sitemap and image checks in the site audit (1.5.0).
 * - site_audits.sitemap_urls: sitemap URLs the audit added or matched; NULL
 *   for audits before 1.5.0, whose pages are never judged against a sitemap.
 * - site_audit_pages.in_sitemap: 1 when the page was listed in a sitemap,
 *   0 when it was only found through links; NULL before 1.5.0.
 * - site_audit_pages.image_signals: images on the page, how many lack width
 *   and height, and whether the first content image is lazy-loaded (JSON).
 * - site_audit_resources.bytes and content_type: an image's full size (from
 *   Content-Range or Content-Length of the 1 KB probe) and media type.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $columns = [
            ['site_audits', 'sitemap_urls', 'INT UNSIGNED NULL'],
            ['site_audit_pages', 'in_sitemap', 'TINYINT(1) NULL'],
            ['site_audit_pages', 'image_signals', 'VARCHAR(500) NULL'],
            ['site_audit_resources', 'bytes', 'INT UNSIGNED NULL'],
            ['site_audit_resources', 'content_type', 'VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL'],
        ];
        foreach ($columns as [$table, $column, $definition]) {
            $exists = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
            if ($exists === 0) {
                $db->statement(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
            }
        }
    }
};
