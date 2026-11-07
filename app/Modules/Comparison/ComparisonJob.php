<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Comparison;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Support\Uuid;

/**
 * Runs one queued "Why they outrank you" analysis (ADR-038). Idempotent:
 * an analysis that is no longer queued is left alone.
 */
final readonly class ComparisonJob implements JobHandler
{
    public function __construct(private ComparisonService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        $id = $payload['analysis_id'] ?? null;
        if (!is_string($id) || !Uuid::isValid($id)) {
            throw new PermanentJobException('Invalid analysis_id.');
        }
        $this->service->run($id);
    }
}
