<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

/**
 * Used when no AI assistant module provides a summarizer: digests go out
 * without an AI summary.
 */
final readonly class NullDigestSummarizer implements DigestSummarizer
{
    public function available(int $workspaceId): bool
    {
        return false;
    }

    public function summarize(int $workspaceId, string $facts): ?string
    {
        return null;
    }
}
