<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\Opportunities\OutcomeService;

final readonly class MeasureOutcomesJob implements JobHandler
{
    public const string TYPE = 'opportunities.outcomes';
    public function __construct(private OutcomeService $outcomes) {}
    public function handle(array $payload, JobContext $context): void
    {
        $this->outcomes->verifyPending();
        $this->outcomes->measureDue();
    }
}
