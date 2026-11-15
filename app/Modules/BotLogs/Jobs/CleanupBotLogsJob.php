<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\BotLogs\BotLogService;

/** Hourly: fails stalled imports, removes old imports and leftover files. */
final readonly class CleanupBotLogsJob implements JobHandler
{
    public function __construct(private BotLogService $logs) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->logs->cleanup();
    }
}
