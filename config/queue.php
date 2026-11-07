<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

// How often the host runs bin/cron.php (minutes). Many shared hosts allow 5 at most.
$interval = max(1, min(60, $env->int('CRON_INTERVAL_MINUTES', 1)));

return [
    'cron_interval_minutes' => $interval,
    // Seconds one cron run may spend on jobs: 50 for a 1-minute cron, up to 4 minutes for
    // longer intervals so throughput stays the same. Keep below the host's CLI time limit.
    'cron_max_seconds' => $env->int('QUEUE_CRON_MAX_SECONDS', $interval > 1 ? min($interval * 60 - 60, 240) : 50),
    // Concurrent worker runs allowed when cron invocations overlap.
    'worker_slots' => $env->int('QUEUE_WORKER_SLOTS', 2),
    // A job's reservation expires after this many seconds unless renewed.
    'lease_seconds' => 120,
    // Soft memory ceiling for a worker run (MB); 0 = 80 % of memory_limit.
    'memory_limit_mb' => $env->int('QUEUE_MEMORY_LIMIT_MB', 0),
];
