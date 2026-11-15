<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteMigration;

/** Queues the background work of a site migration plan (1.2.0). */
interface SiteMigrationScheduler
{
    public function match(int $migrationId, int $workspaceId): void;

    /** $cursor makes a continuation's idempotency key differ from the run it continues. */
    public function test(int $migrationId, int $workspaceId, int $cursor): void;
}
