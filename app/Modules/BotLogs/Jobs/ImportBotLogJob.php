<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\BotLogs\BotLogService;

/**
 * Reads an uploaded access log in chunks; retries resume after the last
 * saved chunk. After the final attempt the import is marked failed and
 * its file deleted.
 */
final readonly class ImportBotLogJob implements JobHandler
{
    public function __construct(private BotLogService $logs) {}

    public function handle(array $payload, JobContext $context): void
    {
        $id = $payload['import_id'] ?? null;
        if (!is_int($id)) {
            throw new PermanentJobException('Invalid bot log payload.');
        }
        try {
            $this->logs->run($id, $context->secondsRemaining(...), $context->renewLease(...));
        } catch (\Throwable $e) {
            if ($context->attempt >= $context->maxAttempts || $e instanceof PermanentJobException) {
                $this->logs->fail($id, 'The log could not be read. Upload it again.');
            }
            throw $e;
        }
    }
}
