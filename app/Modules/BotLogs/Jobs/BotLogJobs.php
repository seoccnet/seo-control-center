<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\BotLogs\BotLogScheduler;

/** Queues bot log imports and their cleanup. */
final readonly class BotLogJobs implements BotLogScheduler
{
    public const string IMPORT = 'bot_logs.import';
    public const string CLEANUP = 'bot_logs.cleanup';

    public function __construct(private Queue $queue) {}

    public function import(int $importId, int $workspaceId, int $offset): void
    {
        $this->queue->dispatch(self::IMPORT, ['import_id' => $importId], 'reports', idempotencyKey: sprintf('bot_logs.import:%d:%d', $importId, $offset), workspaceId: $workspaceId);
    }
}
