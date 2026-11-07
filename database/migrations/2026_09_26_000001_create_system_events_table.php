<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * System events: installation-level operational events shown in System Health
 * (cron heartbeats, update results, license state changes, …).
 * Not the audit log: audit_logs records *who did what* and arrives in slice 2.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS system_events (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_type  VARCHAR(64)     NOT NULL,
                severity    ENUM(\'info\', \'warning\', \'error\') NOT NULL DEFAULT \'info\',
                message     VARCHAR(500)    NOT NULL,
                context     JSON            NULL,
                created_at  DATETIME        NOT NULL,
                PRIMARY KEY (id),
                KEY system_events_type_created (event_type, created_at),
                KEY system_events_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
};
