<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Admin\Persistence;

use SeoControlCenter\Core\Database\Connection;

final readonly class PdoAuditLogReader implements AuditLogReader
{
    public function __construct(private Connection $db) {}

    public function forWorkspace(int $workspaceId, ?int $beforeId = null): array
    {
        $sql = 'SELECT id, actor_type, actor_label, action, target_type, target_id, outcome, ip_address, request_id, metadata, created_at
                  FROM audit_logs
                 WHERE workspace_id = ?';
        $params = [$workspaceId];
        if ($beforeId !== null) {
            $sql .= ' AND id < ?';
            $params[] = $beforeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . self::PAGE_SIZE;

        return $this->db->fetchAll($sql, $params);
    }
}
