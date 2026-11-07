<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Assistant\FixPlan;
use SeoControlCenter\Modules\Assistant\FixPlanRepository;

final readonly class PdoFixPlanRepository implements FixPlanRepository
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function save(int $workspaceId, int $projectId, int $userId, string $title, array $rows): FixPlan
    {
        $publicId = Uuid::v7($this->clock);
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $title = mb_substr($title, 0, 200);
        $this->db->execute(
            'INSERT INTO assistant_fix_plans (public_id, workspace_id, project_id, user_id, title, `rows`, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$publicId, $workspaceId, $projectId, $userId, $title, json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now],
        );

        return new FixPlan($publicId, $workspaceId, $projectId, $userId, $title, $rows, $now);
    }

    public function find(int $workspaceId, int $userId, string $publicId): ?FixPlan
    {
        if (!Uuid::isValid($publicId)) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT public_id, workspace_id, project_id, user_id, title, `rows`, created_at FROM assistant_fix_plans WHERE workspace_id = ? AND user_id = ? AND public_id = ?',
            [$workspaceId, $userId, $publicId],
        );

        return $row === null ? null : new FixPlan(
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (int) $row['project_id'],
            (int) $row['user_id'],
            (string) $row['title'],
            FixPlan::rowsFrom(json_decode((string) $row['rows'], true)),
            (string) $row['created_at'],
        );
    }
}
