<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\System\UpdateCheck;

/** Daily: looks for a new release on GitHub when an administrator turned the check on (ADR-053). */
final readonly class UpdateCheckJob implements JobHandler
{
    public const string TYPE = 'system.update_check';

    public function __construct(private UpdateCheck $check) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->check->check();
    }
}
