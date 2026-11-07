<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS project_alert_webhooks (project_id BIGINT UNSIGNED PRIMARY KEY, public_id CHAR(36) NOT NULL UNIQUE, configured_by BIGINT UNSIGNED NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0, format VARCHAR(10) NOT NULL, destination BLOB NOT NULL, bearer_token BLOB NULL, FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE, FOREIGN KEY (configured_by) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS alert_webhook_deliveries (alert_id BIGINT UNSIGNED PRIMARY KEY, project_id BIGINT UNSIGNED NOT NULL, webhook_public_id CHAR(36) NOT NULL, status VARCHAR(12) NOT NULL, http_status SMALLINT UNSIGNED NULL, message VARCHAR(200) NULL, created_at DATETIME NOT NULL, FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE, FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
