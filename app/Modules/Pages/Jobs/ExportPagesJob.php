<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\Pages\PageExportService;

/**
 * Writes chunks of a Page library export. Retries resume from the last
 * recorded chunk; after the final attempt the export is marked failed.
 */
final readonly class ExportPagesJob implements JobHandler
{
    public function __construct(private PageExportService $exports) {}

    public function handle(array $payload, JobContext $context): void
    {
        $exportId = $payload['export_id'] ?? null;
        if (!is_int($exportId)) {
            throw new PermanentJobException('Invalid page export payload.');
        }
        try {
            $this->exports->run($exportId, $context->secondsRemaining(...), $context->renewLease(...));
        } catch (\Throwable $e) {
            if ($context->attempt >= $context->maxAttempts || $e instanceof PermanentJobException) {
                $this->exports->fail($exportId, 'The export could not be written. Prepare a new export.');
            }
            throw $e;
        }
    }
}
