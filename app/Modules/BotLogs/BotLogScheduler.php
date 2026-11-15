<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs;

/** Queues the reading of an uploaded access log (1.2.0). */
interface BotLogScheduler
{
    /** $offset makes a continuation's idempotency key differ from the run it continues. */
    public function import(int $importId, int $workspaceId, int $offset): void;
}
