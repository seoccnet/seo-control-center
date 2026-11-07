<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS rate_limit_buckets (
                bucket_key   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                hits         INT UNSIGNED NOT NULL,
                window_start DATETIME     NOT NULL,
                expires_at   DATETIME     NOT NULL,
                PRIMARY KEY (bucket_key),
                KEY rate_limit_buckets_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
