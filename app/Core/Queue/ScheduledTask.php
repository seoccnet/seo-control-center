<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

final readonly class ScheduledTask
{
    public CronExpression $schedule;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $name,
        string $cron,
        public string $jobType,
        public string $queue = 'default',
        public array $payload = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]*$/D', $name) !== 1 || strlen($name) > 100) {
            throw new \InvalidArgumentException(sprintf('Invalid scheduled task name "%s".', $name));
        }
        $this->schedule = new CronExpression($cron);
    }
}
