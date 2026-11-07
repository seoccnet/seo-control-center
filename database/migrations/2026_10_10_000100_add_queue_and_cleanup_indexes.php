<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;
use SeoControlCenter\Core\Database\Migrations\Schema;

/*
 * Indexes for shared-hosting load (QUEUE_AND_CRON.md -> Atomic job claiming,
 * System cleanup):
 * - jobs_claim_order lets a worker claim the oldest due job of one queue and
 *   priority by walking the index, so the claim locks one row instead of
 *   sorting (and locking) every due job.
 * - sessions_last_activity, password_resets_expires and system_events cleanup
 *   use bounded range deletes instead of table scans.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        Schema::addIndex($db, 'jobs', 'jobs_claim_order', 'status, queue, priority, available_at');
        Schema::addIndex($db, 'sessions', 'sessions_last_activity', 'last_activity_at');
        Schema::addIndex($db, 'password_resets', 'password_resets_expires', 'expires_at');
    }
};
