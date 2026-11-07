<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * White label (1.1): one row with the installation's branding. Empty values
 * fall back to the defaults (APP_NAME, the SEO Control Center logo and
 * colours). The logo file itself lives in storage/branding/.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS branding_settings (
                id            TINYINT UNSIGNED NOT NULL,
                product_name  VARCHAR(60)     NULL,
                accent_color  CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL,
                report_footer VARCHAR(300)    NULL,
                logo_file     VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
                logo_version  CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
                updated_by    BIGINT UNSIGNED NULL,
                updated_at    DATETIME        NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT branding_settings_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
