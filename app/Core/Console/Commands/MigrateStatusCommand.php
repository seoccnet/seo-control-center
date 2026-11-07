<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console\Commands;

use SeoControlCenter\Core\Console\Command;
use SeoControlCenter\Core\Console\Output;
use SeoControlCenter\Core\Database\Migrations\Migrator;

final readonly class MigrateStatusCommand implements Command
{
    public function __construct(private Migrator $migrator) {}

    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Show applied and pending migrations';
    }

    public function run(array $arguments, Output $output): int
    {
        $rows = [];
        $problems = 0;
        foreach ($this->migrator->status() as $status) {
            $note = match (true) {
                $status->missingFile => 'file missing',
                $status->modifiedAfterApply => 'MODIFIED after apply',
                default => '',
            };
            if ($note !== '') {
                ++$problems;
            }
            $rows[] = [
                $status->name,
                $status->applied ? 'applied' : 'pending',
                (string) ($status->batch ?? ''),
                (string) ($status->appliedAt ?? ''),
                $note,
            ];
        }

        if ($rows === []) {
            $output->line('No migrations found.');

            return 0;
        }
        $output->table(['Migration', 'Status', 'Batch', 'Applied at (UTC)', 'Note'], $rows);

        if ($problems > 0) {
            $output->warning('Applied migrations must never be edited. Create a new migration instead.');
        }

        return 0;
    }
}
