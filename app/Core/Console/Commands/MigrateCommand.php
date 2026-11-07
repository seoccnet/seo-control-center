<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console\Commands;

use SeoControlCenter\Core\Console\Command;
use SeoControlCenter\Core\Console\Output;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migrator;

final readonly class MigrateCommand implements Command
{
    public function __construct(
        private Migrator $migrator,
        private Connection $db,
    ) {}

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Apply pending database migrations';
    }

    public function run(array $arguments, Output $output): int
    {
        $version = $this->db->serverVersion();
        if (!$version->isSupported()) {
            $output->error(sprintf('%s is not supported. Minimum: %s %s (ADR-011).', $version->describe(), $version->product(), $version->minimum()));

            return 1;
        }

        if (in_array('--pretend', $arguments, true)) {
            $pending = $this->migrator->pending();
            foreach ($pending as $file) {
                $output->line('Would apply: ' . $file->name);
            }
            $output->info(sprintf('%d pending migration(s).', count($pending)));

            return 0;
        }

        $applied = $this->migrator->migrate(static function (string $name) use ($output): void {
            $output->line('Applied: ' . $name);
        });

        $output->info($applied === [] ? 'Nothing to migrate.' : sprintf('Applied %d migration(s).', count($applied)));

        return 0;
    }
}
