<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteMigration\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\SiteMigration\SiteMigrationScheduler;

/** Queues redirect suggestions and redirect tests of site migration plans. */
final readonly class SiteMigrationJobs implements SiteMigrationScheduler
{
    public const string RUN = 'site_migration.run';

    public function __construct(private Queue $queue) {}

    public function match(int $migrationId, int $workspaceId): void
    {
        $this->queue->dispatch(self::RUN, ['migration_id' => $migrationId, 'task' => 'match'], 'default', idempotencyKey: sprintf('site_migration.match:%d:%s', $migrationId, bin2hex(random_bytes(4))), workspaceId: $workspaceId);
    }

    public function test(int $migrationId, int $workspaceId, int $cursor): void
    {
        $this->queue->dispatch(self::RUN, ['migration_id' => $migrationId, 'task' => 'test'], 'default', idempotencyKey: sprintf('site_migration.test:%d:%d:%s', $migrationId, $cursor, bin2hex(random_bytes(4))), workspaceId: $workspaceId);
    }
}
