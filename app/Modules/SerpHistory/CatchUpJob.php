<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SerpHistory;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;

/** The daily pass of results-page change detection (ADR-039, E1). */
final readonly class CatchUpJob implements JobHandler
{
    public const string TYPE = 'serp_history.catch_up';

    public function __construct(private SerpHistoryService $history) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->history->catchUp();
    }
}
