<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Admin\Persistence;

/**
 * Read model for the audit log screen. Always scoped to one workspace.
 */
interface AuditLogReader
{
    public const int PAGE_SIZE = 50;

    /**
     * Newest first; pass the smallest ID of the previous page to continue.
     *
     * @return list<array<string, mixed>>
     */
    public function forWorkspace(int $workspaceId, ?int $beforeId = null): array;
}
