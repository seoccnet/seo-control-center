<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

/**
 * Writes the short AI summary at the top of a weekly digest, with the
 * workspace's AI assistant (Modules\Assistant). Null when the workspace has
 * no assistant, the budget is used up or the model fails: the digest is sent
 * without a summary.
 */
interface DigestSummarizer
{
    public function available(int $workspaceId): bool;

    public function summarize(int $workspaceId, string $facts): ?string;
}
