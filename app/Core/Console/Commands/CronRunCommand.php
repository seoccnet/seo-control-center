<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console\Commands;

use SeoControlCenter\Core\Console\Command;
use SeoControlCenter\Core\Console\Output;
use SeoControlCenter\Core\Queue\CronRunner;

final readonly class CronRunCommand implements Command
{
    public function __construct(private CronRunner $runner) {}

    public function name(): string
    {
        return 'cron:run';
    }

    public function description(): string
    {
        return 'Run the scheduler and queue worker once (same as bin/cron.php)';
    }

    public function run(array $arguments, Output $output): int
    {
        $result = $this->runner->run();
        $output->line(sprintf(
            'Scheduled: %s | released stale: %d | processed: %d | worker slot: %s',
            $result['scheduled'] === [] ? 'none' : implode(', ', $result['scheduled']),
            $result['released'],
            $result['processed'],
            $result['slot'] === null ? 'none free' : (string) $result['slot'],
        ));

        return 0;
    }
}
