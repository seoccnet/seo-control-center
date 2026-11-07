<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS site_audit_resources (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, audit_id BIGINT UNSIGNED NOT NULL, url_hash BINARY(32) NOT NULL, url VARCHAR(2048) NOT NULL, state VARCHAR(10) NOT NULL DEFAULT \'pending\', status_code SMALLINT UNSIGNED NULL, UNIQUE KEY audit_url (audit_id, url_hash), FOREIGN KEY (audit_id) REFERENCES site_audits(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->statement('CREATE TABLE IF NOT EXISTS site_audit_resource_refs (audit_id BIGINT UNSIGNED NOT NULL, page_id BIGINT UNSIGNED NOT NULL, resource_id BIGINT UNSIGNED NOT NULL, kind VARCHAR(20) NOT NULL, PRIMARY KEY (page_id, resource_id, kind), FOREIGN KEY (audit_id) REFERENCES site_audits(id) ON DELETE CASCADE, FOREIGN KEY (page_id) REFERENCES site_audit_pages(id) ON DELETE CASCADE, FOREIGN KEY (resource_id) REFERENCES site_audit_resources(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
